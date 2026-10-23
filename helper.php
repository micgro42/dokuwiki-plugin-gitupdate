<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\HTTP\DokuHTTPClient;
use dokuwiki\Logger;

/**
 * DokuWiki Plugin gitupdate (Helper Component)
 *
 * Compares the checked out commit with the head of the tracked branch on
 * GitHub. The core update check compares $updateVersion with
 * update.dokuwiki.org. That number is usually already increased on master.
 * Thus a wiki that follows master gets no message about new commits.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <mic.grosse+gitupdate@googlemail.com>
 */
class helper_plugin_gitupdate extends Plugin
{
    /** @var int number of new commits is not known */
    public const AHEAD_UNKNOWN = -1;

    /** @var string base of the GitHub API */
    protected const API = 'https://api.github.com/repos/';

    /** @var string base of the GitHub web site */
    protected const WEB = 'https://github.com/';

    /** @var int stop the compare request if the answer is larger than this */
    protected const MAX_BODYSIZE = 4194304;

    /** @var int shortest commit hash that git makes */
    protected const MIN_SHA_LENGTH = 7;

    /** @var array|null result of the last check, for this request */
    protected $data;

    /** @var string|null the local commit, for this request */
    protected $localSha;

    /**
     * Get the result of the last check, if it shows new commits
     *
     * The result is only applicable to the commit that was checked out when the
     * check ran. After a pull the plugin must do a new check.
     *
     * @return array|null the check result, or null if there is nothing to show
     */
    public function getStatus()
    {
        $data = $this->readCache();
        if ($data['ahead'] === 0) return null;
        if ($data['local'] !== $this->getLocalSha()) return null;
        return $data;
    }

    /**
     * Is a new check necessary?
     *
     * @return bool
     */
    public function isDue()
    {
        $local = $this->getLocalSha();
        if ($local === '') return false; // not a git checkout

        $data = $this->readCache();
        if ($data['local'] !== $local) return true; // the wiki was updated since the last check

        $interval = max(1, (int)$this->getConf('interval')) * 60 * 60;
        return $data['ts'] + $interval < time();
    }

    /**
     * Ask GitHub about the tracked branch and cache the answer
     *
     * This does one small request to get the head of the branch. Only if that
     * head differs from the local commit, it does a second request to count the
     * new commits. The time stamp changes before the request. Thus a broken
     * connection does not cause a request on every task runner call. Two task
     * runners that start at the same time also do not do the same request.
     *
     * @return bool true if the check gave a new result
     */
    public function refresh()
    {
        $local = $this->getLocalSha();
        $repo = $this->getRepo();
        $branch = $this->getBranch();
        if ($local === '' || $repo === '' || $branch === '') {
            Logger::debug('gitupdate: no git checkout or no repository found', [$local, $repo, $branch]);
            return false;
        }

        // Change the time stamp before the request, not after it.
        $this->delayNextCheck($local);

        $head = $this->fetchBranchHead($repo, $branch);
        if ($head === null) return false;

        $data = $this->emptyRecord();
        $data['ts'] = time();
        $data['local'] = $local;
        $data['repo'] = $repo;
        $data['branch'] = $branch;
        $data = array_merge($data, $head);

        if ($this->isSameCommit($local, $data['sha'])) {
            $this->saveCache($data); // up to date, no second request necessary
            return true;
        }

        $data['ahead'] = $this->fetchAheadCount($repo, $local, $branch);
        $data['url'] = self::WEB . $repo . '/compare/' . $local . '...' . $this->encodeRef($branch);
        $this->saveCache($data);
        return true;
    }

    /**
     * Get the newest commit of the branch
     *
     * The commit list endpoint is used because it sends no file diff.
     *
     * @param string $repo the repository, as owner/name
     * @param string $branch the tracked branch
     * @return array|null the commit fields for the cache record, or null on an error
     */
    protected function fetchBranchHead($repo, $branch)
    {
        $url = self::API . $repo . '/commits?per_page=1&sha=' . rawurlencode($branch);
        $json = $this->fetchJson($url, self::MAX_BODYSIZE);
        if (!isset($json[0]['sha'])) {
            Logger::debug('gitupdate: no usable commit list from GitHub', [$url]);
            return null;
        }

        $commit = $json[0];
        $sha = $this->filterSha((string)$commit['sha']);
        if ($sha === '') {
            Logger::debug('gitupdate: GitHub sent no usable commit hash', [$url]);
            return null;
        }

        $message = (string)($commit['commit']['message'] ?? '');
        return [
            'sha' => $sha,
            'date' => (string)($commit['commit']['author']['date'] ?? ''),
            'subject' => trim(explode("\n", $message)[0]),
        ];
    }

    /**
     * Count the commits that the branch has and the local checkout does not have
     *
     * @param string $repo the repository, as owner/name
     * @param string $local the local commit
     * @param string $branch the tracked branch
     * @return int the number of new commits, or AHEAD_UNKNOWN if the request failed
     */
    protected function fetchAheadCount($repo, $local, $branch)
    {
        // per_page keeps the commit list short. The file diff is sent anyway.
        $url = self::API . $repo . '/compare/' . $local . '...' . $this->encodeRef($branch) . '?per_page=1';
        $json = $this->fetchJson($url, self::MAX_BODYSIZE);
        if (!isset($json['ahead_by'])) {
            Logger::debug('gitupdate: cannot count the new commits', [$url]);
            return self::AHEAD_UNKNOWN;
        }
        return (int)$json['ahead_by'];
    }

    /**
     * Make a branch name safe for the path part of a URL
     *
     * A branch name can contain a slash. The slash must stay a slash, because
     * it is a part of the path.
     *
     * @param string $ref the branch name
     * @return string
     */
    protected function encodeRef($ref)
    {
        return str_replace('%2F', '/', rawurlencode($ref));
    }

    /**
     * Do a GET request to the GitHub API
     *
     * @param string $url the full URL
     * @param int $maxsize abort if the answer is larger, 0 for no limit
     * @return array|null the decoded answer, or null on an error
     */
    protected function fetchJson($url, $maxsize)
    {
        $http = new DokuHTTPClient();
        $http->timeout = 15;
        $http->agent = 'DokuWiki gitupdate plugin';
        $http->max_bodysize = $maxsize;
        $http->headers['Accept'] = 'application/vnd.github+json';
        $http->headers['X-GitHub-Api-Version'] = '2022-11-28';

        $resp = $http->get($url);
        if (!is_string($resp)) {
            Logger::debug('gitupdate: request failed', [$url, $http->status, $http->error]);
            return null;
        }

        $json = json_decode($resp, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Are these the same commit?
     *
     * One of the two hashes can be abbreviated. But a hash that is too short
     * gives a match with many commits. Such a hash is not accepted.
     *
     * @param string $one
     * @param string $two
     * @return bool
     */
    protected function isSameCommit($one, $two)
    {
        $len = min(strlen($one), strlen($two));
        if ($len < self::MIN_SHA_LENGTH) return false;
        return substr($one, 0, $len) === substr($two, 0, $len);
    }

    /**
     * Delay the next request
     *
     * The last result stays in the cache. But a result for a different commit is
     * not applicable any more. Then the record is replaced.
     *
     * @param string $local the commit that is checked out
     * @return void
     */
    protected function delayNextCheck($local)
    {
        $data = $this->readCache();
        if ($data['local'] !== $local) {
            $data = $this->emptyRecord();
            $data['local'] = $local;
        }
        $data['ts'] = time();
        $this->saveCache($data);
    }

    /**
     * Get the commit that is checked out
     *
     * This reads the git files directly. It does not call git, because this
     * runs on every request of an admin.
     *
     * @return string the commit hash, or an empty string if this is no git checkout
     */
    public function getLocalSha()
    {
        if ($this->localSha !== null) return $this->localSha;
        $this->localSha = '';

        $head = $this->readGitFile('HEAD');
        if ($head === '') return $this->localSha;

        if (!str_starts_with($head, 'ref: ')) {
            // detached head, the file holds the commit
            $this->localSha = $this->filterSha($head);
            return $this->localSha;
        }

        $ref = trim(substr($head, 5));
        $sha = $this->readGitFile($ref);
        if ($sha === '') {
            // the ref is packed
            $packed = $this->readGitFile('packed-refs');
            if (preg_match('/^([0-9a-f]{40,64}) ' . preg_quote($ref, '/') . '$/m', $packed, $match)) {
                $sha = $match[1];
            }
        }

        $this->localSha = $this->filterSha($sha);
        return $this->localSha;
    }

    /**
     * Get the branch to compare against
     *
     * @return string the branch name, or an empty string if there is none
     */
    public function getBranch()
    {
        $branch = trim((string)$this->getConf('branch'));
        if ($branch !== '') return $branch;

        $head = $this->readGitFile('HEAD');
        if (preg_match('#^ref: refs/heads/(.+)$#', $head, $match)) return trim($match[1]);

        return ''; // detached head, we do not know the branch
    }

    /**
     * Get the GitHub repository to compare against
     *
     * @return string the repository as owner/name, or an empty string if there is none
     */
    public function getRepo()
    {
        $repo = trim((string)$this->getConf('repo'));
        // A bad value is not replaced by the remote from the git config. The
        // administrator must see that the setting does not work.
        if ($repo !== '') return $this->filterRepo($repo);

        // take the first GitHub remote from the git config
        $config = $this->readGitFile('config');
        if (preg_match('#\bgithub\.com[:/]([\w.-]+/[\w.-]+?)(?:\.git)?\s*$#m', $config, $match)) {
            return $this->filterRepo($match[1]);
        }

        return '';
    }

    /**
     * Read a file from the git directory
     *
     * @param string $path the path, relative to the git directory
     * @return string the trimmed content, or an empty string
     */
    protected function readGitFile($path)
    {
        // keep the read inside the git directory
        if (str_contains($path, '..')) return '';

        $file = DOKU_INC . '.git/' . $path;
        if (!file_exists($file) || !is_file($file)) return '';
        return trim((string)@file_get_contents($file));
    }

    /**
     * Accept only a repository name
     *
     * The name goes into the path of the request URL. Other characters can make
     * a bad request.
     *
     * @param string $repo
     * @return string the repository as owner/name, or an empty string
     */
    protected function filterRepo($repo)
    {
        return preg_match('#^[\w.-]+/[\w.-]+\z#', $repo) ? $repo : '';
    }

    /**
     * Accept only a commit hash
     *
     * @param string $sha
     * @return string the hash, or an empty string
     */
    protected function filterSha($sha)
    {
        return preg_match('/^[0-9a-f]{' . self::MIN_SHA_LENGTH . ',64}\z/', $sha) ? $sha : '';
    }

    /**
     * Get an empty cache record
     *
     * @return array
     */
    protected function emptyRecord()
    {
        return [
            'ts' => 0,        // time of the last check
            'local' => '',    // the commit that was checked
            'repo' => '',     // the repository that was asked
            'branch' => '',   // the branch that was compared
            'ahead' => 0,     // number of new commits, or AHEAD_UNKNOWN
            'url' => '',      // link to the comparison
            'sha' => '',      // the newest commit on the branch
            'date' => '',     // date of the newest commit
            'subject' => '',  // first line of the newest commit message
        ];
    }

    /**
     * Read the cached check result
     *
     * @return array always a full record, empty if there is no cache yet
     */
    protected function readCache()
    {
        if ($this->data !== null) return $this->data;

        $data = json_decode(io_readFile($this->getCacheFile()), true);
        if (!is_array($data)) $data = [];
        $this->data = array_merge($this->emptyRecord(), $data);
        return $this->data;
    }

    /**
     * Write the check result to the cache
     *
     * @param array $data
     * @return void
     */
    protected function saveCache($data)
    {
        $this->data = array_merge($this->emptyRecord(), $data);
        io_saveFile($this->getCacheFile(), json_encode($this->data));
    }

    /**
     * Get the file that holds the check result
     *
     * @return string
     */
    protected function getCacheFile()
    {
        return getCacheName('plugin_gitupdate', '.json');
    }
}
