<?php
/**
 * This file is part of the SVN-Buddy Updater library.
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @copyright Alexander Obuhovich <aik.bold@gmail.com>
 * @link      https://github.com/console-helpers/svn-buddy-updater
 */

namespace ConsoleHelpers\SvnBuddyUpdater;


use Aura\Sql\ExtendedPdoInterface;
use Aws\S3\S3Client;
use Github\Client;
use Github\HttpClient\CachedHttpClient;
use Symfony\Component\Process\ProcessBuilder;

class ReleaseManager
{

	const STABILITY_STABLE = 'stable';

	const STABILITY_SNAPSHOT = 'snapshot';

	const SNAPSHOT_LIFETIME = '3 weeks';

	/**
	 * Database
	 *
	 * @var ExtendedPdoInterface
	 */
	private $_db;

	/**
	 * Mapping between downloaded files and columns, where they are stored.
	 *
	 * @var array
	 */
	private $_fileMapping = array(
		'svn-buddy.phar' => 'phar_download_url',
		'svn-buddy.phar.sig' => 'signature_download_url',
	);

	/**
	 * Repository path.
	 *
	 * @var string
	 */
	private $_repositoryPath;

	/**
	 * Snapshots path.
	 *
	 * @var string
	 */
	private $_snapshotsPath;

	/**
	 * Name of S3 bucket where snapshots are stored.
	 *
	 * @var string
	 */
	private $_s3BucketName;

	/**
	 * Creates release manager instance.
	 *
	 * @param ExtendedPdoInterface $db Database.
	 */
	public function __construct(ExtendedPdoInterface $db)
	{
		$this->_db = $db;
		$this->_repositoryPath = realpath(__DIR__ . '/../../workspace/repository');
		$this->_snapshotsPath = realpath(__DIR__ . '/../../workspace/snapshots');
		$this->_s3BucketName = $_SERVER['S3_BUCKET'];
	}

	/**
	 * Syncs releases from GitHub.
	 *
	 * @return void
	 */
	public function syncReleasesFromGitHub()
	{
		$this->_deleteReleases(self::STABILITY_STABLE);

		foreach ( $this->_getReleasesFromGitHub() as $release_data ) {
			$bind_params = array(
				'version_name' => $release_data['name'],
				'release_date' => strtotime($release_data['published_at']),
				'phar_download_url' => '',
				'signature_download_url' => '',
				'stability' => self::STABILITY_STABLE,
			);

			foreach ( $release_data['assets'] as $asset_data ) {
				$asset_name = $asset_data['name'];

				if ( isset($this->_fileMapping[$asset_name]) ) {
					$bind_params[$this->_fileMapping[$asset_name]] = $asset_data['browser_download_url'];
				}
			}

			$sql = 'INSERT INTO releases (version_name, release_date, phar_download_url, signature_download_url, stability)
					VALUES (:version_name, :release_date, :phar_download_url, :signature_download_url, :stability)';
			$this->_db->perform($sql, $bind_params);
		}
	}

	/**
	 * Returns releases from GitHub.
	 *
	 * @return array
	 */
	private function _getReleasesFromGitHub()
	{
		$client = new Client(
			new CachedHttpClient(array('cache_dir' => '/tmp/github-api-cache'))
		);

		return $client->api('repo')->releases()->all('console-helpers', 'svn-buddy');
	}

	/**
	 * Syncs releases from GitHub.
	 *
	 * @return void
	 */
	public function syncReleasesFromRepository()
	{
		$commit_data = $this->_getCommitForSnapshotRelease();

		if ( $commit_data ) {
			$this->_createSnapshotRelease($commit_data[0], $commit_data[1]);
		}

		$this->_deleteOldSnapshots();
	}

	/**
	 * Returns commit hash/date for next snapshot release.
	 *
	 * @return array
	 * @throws \LogicException When failed to get commit.
	 */
	private function _getCommitForSnapshotRelease()
	{
		$this->_gitCommand('checkout', array('master'));
		$this->_gitCommand('pull');

		$this_week_monday = strtotime(date('Y') . 'W' . date('W'));

		$output = $this->_gitCommand('log', array(
			'--format=%H:%cd',
			'--max-count=1',
			'--before=' . date('Y-m-d', $this_week_monday),
		));

		if ( strpos($output, ':') === false ) {
			throw new \LogicException('Unable to detect commit for the snapshot.');
		}

		return explode(':', trim($output), 2);
	}

	/**
	 * Generates phar for snapshot release.
	 *
	 * @param string $commit_hash Commit hash.
	 * @param string $commit_date Commit date.
	 *
	 * @return void
	 */
	private function _createSnapshotRelease($commit_hash, $commit_date)
	{
		$sql = 'SELECT version_name
				FROM releases
				WHERE version_name = :version';
		$found_version = $this->_db->fetchValue($sql, array('version' => $commit_hash));

		if ( $found_version === $commit_hash ) {
			return;
		}

		list($phar_download_url, $signature_download_url) = $this->_createPhar($commit_hash);

		$bind_params = array(
			'version_name' => $commit_hash,
			'release_date' => strtotime($commit_date),
			'phar_download_url' => $phar_download_url,
			'signature_download_url' => $signature_download_url,
			'stability' => self::STABILITY_SNAPSHOT,
		);

		$sql = 'INSERT INTO releases (version_name, release_date, phar_download_url, signature_download_url, stability)
				VALUES (:version_name, :release_date, :phar_download_url, :signature_download_url, :stability)';
		$this->_db->perform($sql, $bind_params);
	}

	/**
	 * Creates phar.
	 *
	 * @param string $commit_hash Commit hash.
	 *
	 * @return array
	 */
	private function _createPhar($commit_hash)
	{
		$this->_gitCommand('checkout', array($commit_hash));

		$this->_shellCommand(
			$this->_repositoryPath . '/bin/svn-buddy',
			array(
				'dev:phar-create',
				'--build-dir=' . $this->_snapshotsPath,
			)
		);

		$phar_file = $this->_snapshotsPath . '/svn-buddy.phar';
		$signature_file = $this->_snapshotsPath . '/svn-buddy.phar.sig';

		return $this->_uploadToS3(
			'snapshots/' . $commit_hash,
			array($phar_file, $signature_file)
		);
	}

	/**
	 * Deletes old snapshots.
	 *
	 * @return void
	 */
	private function _deleteOldSnapshots()
	{
		$latest_versions = $this->getLatestVersionsForStability();

		if ( !isset($latest_versions[self::STABILITY_SNAPSHOT]) ) {
			return;
		}

		$sql = 'SELECT version_name
				FROM releases
				WHERE stability = :stability AND release_date < :release_date AND version_name != :latest_version
				ORDER BY release_date ASC';
		$versions = $this->_db->fetchCol($sql, array(
			'stability' => self::STABILITY_SNAPSHOT,
			'release_date' => strtotime('-' . self::SNAPSHOT_LIFETIME),
			'latest_version' => $latest_versions[self::STABILITY_SNAPSHOT]['version'],
		));

		if ( !$versions ) {
			return;
		}

		// Delete associated S3 objects.
		$s3_objects = array();

		foreach ( $versions as $version ) {
			$s3_objects[] = array('Key' => 'snapshots/' . $version . '/svn-buddy.phar');
			$s3_objects[] = array('Key' => 'snapshots/' . $version . '/svn-buddy.phar.sig');
			$s3_objects[] = array('Key' => 'snapshots/' . $version);
		}

		$s3 = S3Client::factory();
		$s3->deleteObjects(array(
			'Bucket' => $this->_s3BucketName,
			'Objects' => $s3_objects,
		));

		// Delete versions.
		$sql = 'DELETE FROM releases
				WHERE version_name IN (:versions)';
		$this->_db->perform($sql, array(
			'versions' => $versions,
		));
	}

	/**
	 * Uploads files to S3.
	 *
	 * @param string $parent_folder Parent folder.
	 * @param array  $files         Files.
	 *
	 * @return array
	 */
	private function _uploadToS3($parent_folder, array $files)
	{
		$urls = array();
		$s3 = S3Client::factory();

		foreach ( $files as $index => $file ) {
			$uploaded = $s3->upload(
				$this->_s3BucketName,
				$parent_folder . '/' . basename($file),
				fopen($file, 'rb'),
				'public-read'
			);

			$urls[$index] = $uploaded->get('ObjectURL');
		}

		return $urls;
	}

	/**
	 * Deletes releases.
	 *
	 * @param string $stability Stability.
	 *
	 * @return void
	 */
	private function _deleteReleases($stability)
	{
		$sql = 'DELETE FROM releases
				WHERE stability = :stability';
		$this->_db->perform($sql, array(
			'stability' => $stability,
		));
	}

	/**
	 * Runs git command.
	 *
	 * @param string $command   Command.
	 * @param array  $arguments Arguments.
	 *
	 * @return string
	 */
	private function _gitCommand($command, array $arguments = array())
	{
		array_unshift($arguments, $command);

		return $this->_shellCommand('git', $arguments, $this->_repositoryPath);
	}

	/**
	 * Runs command.
	 *
	 * @param string      $command           Command.
	 * @param array       $arguments         Arguments.
	 * @param string|null $working_directory Working directory.
	 *
	 * @return string
	 */
	private function _shellCommand($command, array $arguments = array(), $working_directory = null)
	{
		$final_arguments = array_merge(array($command), $arguments);

		$process = ProcessBuilder::create($final_arguments)
			->setWorkingDirectory($working_directory)
			->getProcess();

		return $process->mustRun()->getOutput();
	}

	/**
	 * Returns latest versions for each stability.
	 *
	 * @return array
	 */
	public function getLatestVersionsForStability()
	{
		$sql = 'SELECT stability, MAX(release_date)
				FROM releases
				GROUP BY stability';
		$stabilities = $this->_db->fetchPairs($sql);

		$versions = array();

		foreach ( $stabilities as $stability => $release_date ) {
			$sql = 'SELECT version_name
					FROM releases
					WHERE stability = :stability AND release_date = :release_date';
			$release_data = $this->_db->fetchOne($sql, array(
				'stability' => $stability,
				'release_date' => $release_date,
			));

			$versions[$stability] = array(
				'path' => '/download/' . $release_data['version_name'] . '/svn-buddy.phar',
				'version' => $release_data['version_name'],
				'min-php' => 50300,
			);
		}

		return $versions;
	}

	/**
	 * Returns download url for version.
	 *
	 * @param string $version Version.
	 * @param string $file    File.
	 *
	 * @return string
	 */
	public function getDownloadUrl($version, $file)
	{
		$file_mapping = array(
			'svn-buddy.phar' => 'phar_download_url',
			'svn-buddy.phar.sig' => 'signature_download_url',
		);

		if ( !isset($this->_fileMapping[$file]) ) {
			return '';
		}

		$sql = 'SELECT ' . $file_mapping[$file] . '
				FROM releases
				WHERE version_name = :version';
		$download_url = $this->_db->fetchValue($sql, array('version' => $version));

		return (string)$download_url;
	}

}
