<?php
/**
 * Sync one file backend to another based on the journal of later.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @ingroup Maintenance
 */

require_once( dirname( __FILE__ ) . '/Maintenance.php' );

class SyncFileBackend extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Sync one file backend with another using the journal";
		$this->addOption( 'src', 'Name of backend to sync from', true, true );
		$this->addOption( 'dst', 'Name of destination backend to sync', true, true );
		$this->addOption( 'start', 'Starting journal ID', false, true );
		$this->addOption( 'end', 'Ending journal ID', false, true );
		$this->addOption( 'posdir', 'Directory to read/record journal positions', false, true );
		$this->addOption( 'verbose', 'Verbose mode', false, false, 'v' );
		$this->setBatchSize( 50 );
	}

	public function execute() {
		$src = FileBackendGroup::singleton()->get( $this->getOption( 'src' ) );
		$dst = FileBackendGroup::singleton()->get( $this->getOption( 'dst' ) );

		$posDir = $this->getOption( 'posdir' );
		$posFile = $posDir ? $posDir . '/' . wfWikiID() : false;

		$start = $this->getOption( 'start', 0 );
		if ( !$start && $posFile && is_dir( $posDir ) ) {
			$start = is_file( $posFile )
				? (int)trim( file_get_contents( $posFile ) )
				: 0;
			++$start; // we already did this ID, start with the next one
			$startFromPosFile = true;
		} else {
			$startFromPosFile = false;
		}
		$end = $this->getOption( 'end', INF );

		$this->output( "Synchronizing backend '{$dst->getName()}' to '{$src->getName()}'...\n" );
		$this->output( "Starting journal position is $start.\n" );
		if ( is_finite( $end ) ) {
			$this->output( "Ending journal position is $end.\n" );
		}

		// Actually sync the dest backend with the reference backend
		$lastOKPos = $this->syncBackends( $src, $dst, $start, $end );

		// Update the sync position file
		if ( $startFromPosFile && $lastOKPos >= $start ) { // successfully advanced
			if ( file_put_contents( $posFile, $lastOKPos, LOCK_EX ) !== false ) {
				$this->output( "Updated journal position file.\n" );
			} else {
				$this->output( "Could not update journal position file.\n" );
			}
		}

		if ( $lastOKPos === false ) {
			if ( !$start ) {
				$this->output( "No journal entries found.\n" );
			} else {
				$this->output( "No new journal entries found.\n" );
			}
		} else {
			$this->output( "Stopped synchronization at journal position $lastOKPos.\n" );
		}

		if ( $this->isQuiet() ) {
			print $lastOKPos; // give a single machine-readable number
		}
	}

	/**
	 * Sync $dst backend to $src backend based on the $src logs given after $start.
	 * Returns the journal entry ID this advanced to and handled (inclusive).
	 *
	 * @param $src FileBackend
	 * @param $dst FileBackend
	 * @param $start integer Starting journal position
	 * @param $end integer Starting journal position
	 * @return integer|false Journal entry ID or false if there are none
	 */
	protected function syncBackends( FileBackend $src, FileBackend $dst, $start, $end ) {
		$lastOKPos = 0; // failed
		$first = true; // first batch

		if ( $start > $end ) { // sanity
			$this->error( "Error: given starting ID greater than ending ID.", 1 );
		}

		do {
			$limit = min( $this->mBatchSize, $end - $start + 1 ); // don't go pass ending ID
			$this->output( "Doing id $start to " . ( $start + $limit - 1 ) . "...\n" );

			$entries = $src->getJournal()->getChangeEntries( $start, $limit, $next );
			$start = $next; // start where we left off next time
			if ( $first && !count( $entries ) ) {
				return false; // nothing to do
			}
			$first = false;

			$lastPosInBatch = 0;
			$pathsInBatch = array(); // changed paths
			foreach ( $entries as $entry ) {
				if ( $entry['op'] !== 'null' ) { // null ops are just for reference
					$pathsInBatch[$entry['path']] = 1; // remove duplicates
				}
				$lastPosInBatch = $entry['id'];
			}

			$status = $this->syncFileBatch( array_keys( $pathsInBatch ), $src, $dst );
			if ( $status->isOK() ) {
				$lastOKPos = max( $lastOKPos, $lastPosInBatch );
			} else {
				$this->error( print_r( $status->getErrorsArray(), true ) );
				break; // no gaps; everything up to $lastPos must be OK
			}

			if ( !$start ) {
				$this->output( "End of journal entries.\n" );
			}
		} while ( $start && $start <= $end );

		return $lastOKPos;
	}

	/**
	 * Sync particular files of backend $src to the corresponding $dst backend files
	 *
	 * @param $paths Array
	 * @param $src FileBackend
	 * @param $dst FileBackend
	 * @return Status
	 */
	protected function syncFileBatch( array $paths, FileBackend $src, FileBackend $dst ) {
		$status = Status::newGood();
		if ( !count( $paths ) ) {
			return $status; // nothing to do
		}

		// Source: convert internal backend names (FileBackendMultiWrite) to the public one
		$sPaths = $this->replaceNamePaths( $paths, $src );
		// Destination: get corresponding path name
		$dPaths = $this->replaceNamePaths( $paths, $dst );

		// Lock the live backend paths from modification
		$sLock = $src->getScopedFileLocks( $sPaths, LockManager::LOCK_UW, $status );
		$eLock = $dst->getScopedFileLocks( $dPaths, LockManager::LOCK_EX, $status );
		if ( !$status->isOK() ) {
			return $status;
		}

		$ops = array();
		$fsFiles = array();
		foreach ( $sPaths as $i => $sPath ) {
			$dPath = $dPaths[$i]; // destination
			$sExists = $src->fileExists( array( 'src' => $sPath, 'latest' => 1 ) );
			if ( $sExists === true ) { // exists in source
				if ( $this->filesAreSame( $src, $dst, $sPath, $dPath ) ) {
					continue; // avoid local copies for non-FS backends
				}
				// Note: getLocalReference() is fast for FS backends
				$fsFile = $src->getLocalReference( array( 'src' => $sPath, 'latest' => 1 ) );
				if ( !$fsFile ) {
					$this->error( "Unable to sync '$dPath' to '$sPath': could not get local copy." );
					print_r( $src->getFileStat( array( 'src' => $sPath, 'latest' => 1 ) ) );
					$status->fatal( 'backend-fail-internal', $src->getName() );
					return $status;
				}
				$fsFiles[] = $fsFile; // keep TempFSFile objects alive as needed
				// Note: prepare() is usually fast for key/value backends
				$status->merge( $dst->prepare( array(
					'dir' => dirname( $dPath ), 'bypassReadOnly' => 1 ) ) );
				if ( !$status->isOK() ) {
					return $status;
				}
				$ops[] = array( 'op' => 'store',
					'src' => $fsFile->getPath(), 'dst' => $dPath, 'overwrite' => 1 );
			} elseif ( $sExists === false ) { // does not exist in source
				$ops[] = array( 'op' => 'delete', 'src' => $dPath, 'ignoreMissingSource' => 1 );
			} else { // error
				$this->error( "Unable to sync '$dPath': could not stat file." );
				$status->fatal( 'backend-fail-internal', $src->getName() );
				return $status;
			}
		}

		$t_start = microtime( true );
		$status->merge( $dst->doQuickOperations( $ops, array( 'bypassReadOnly' => 1 ) ) );
		$ellapsed_ms = floor( ( microtime( true ) - $t_start ) * 1000 );
		if ( $status->isOK() && $this->getOption( 'verbose' ) ) {
			$this->output( "Synchronized these file(s) [{$ellapsed_ms}ms]:\n" .
				implode( "\n", $dPaths ) . "\n" );
		}

		return $status;
	}

	/**
	 * Substitute the backend name of storage paths with that of a given one
	 *
	 * @param $paths Array|string List of paths or single string path
	 * @return Array|string
	 */
	protected function replaceNamePaths( $paths, FileBackend $backend ) {
		return preg_replace(
			'!^mwstore://([^/]+)!',
			StringUtils::escapeRegexReplacement( "mwstore://" . $backend->getName() ),
			$paths // string or array
		);
	}

	protected function filesAreSame( FileBackend $src, FileBackend $dst, $sPath, $dPath ) {
		return (
			( $src->getFileSize( array( 'src' => $sPath ) )
				=== $dst->getFileSize( array( 'src' => $dPath ) ) // short-circuit
			) && ( $src->getFileSha1Base36( array( 'src' => $sPath ) )
				=== $dst->getFileSha1Base36( array( 'src' => $dPath ) )
			)
		);
	}
}

$maintClass = "SyncFileBackend";
require_once( RUN_MAINTENANCE_IF_MAIN );