<?php

use dokuwiki\plugin\config\core\Setting\SettingString;

/**
 * Test double for the gitupdate helper
 *
 * It replaces the two parts that touch the outside: the HTTP request and the
 * read of the git directory.
 */
class helper_plugin_gitupdate_mock extends helper_plugin_gitupdate
{
    /** @var array answers for fetchJson(), in order */
    public $responses = [];

    /** @var string[] the URLs that fetchJson() received */
    public $requested = [];

    /** @var array|null content for readGitFile(), null to read the real files */
    public $gitFiles;

    /** @var bool|null the result of isDue() at the time of the first request */
    public $dueAtRequest;

    /** @inheritDoc */
    protected function fetchJson($url, $maxsize)
    {
        $this->requested[] = $url;

        if ($this->dueAtRequest === null) {
            // A second object reads the cache file again. This shows what a
            // parallel task runner sees while this request runs.
            $other = new self();
            $other->gitFiles = $this->gitFiles;
            $this->dueAtRequest = $other->isDue();
        }

        return array_shift($this->responses);
    }

    /** @inheritDoc */
    protected function readGitFile($path)
    {
        if ($this->gitFiles === null) return parent::readGitFile($path);
        return $this->gitFiles[$path] ?? '';
    }
}

/**
 * Tests for the gitupdate helper
 *
 * @group plugin_gitupdate
 * @group plugins
 */
class helper_plugin_gitupdate_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['gitupdate', 'config'];

    /** @var string a hash with the full length */
    protected const SHA = '1111111111111111111111111111111111111111';

    /** @var string a different hash with the full length */
    protected const OTHER_SHA = '2222222222222222222222222222222222222222';

    /** @inheritDoc */
    public function setUp(): void
    {
        parent::setUp();
        // each test starts without a result from an earlier check
        @unlink(getCacheName('plugin_gitupdate', '.json'));
    }

    /**
     * Make a helper that reads the given git files
     *
     * @param array $files content for readGitFile()
     * @return helper_plugin_gitupdate_mock
     */
    protected function makeHelper($files = [])
    {
        $helper = new helper_plugin_gitupdate_mock();
        $helper->gitFiles = array_merge([
            'HEAD' => 'ref: refs/heads/master',
            'refs/heads/master' => self::SHA,
            'config' => "[remote \"origin\"]\n\turl = git@github.com:dokuwiki/dokuwiki.git\n",
        ], $files);
        return $helper;
    }

    /**
     * Make the answer of the commit list endpoint
     *
     * @param string $sha the newest commit on the branch
     * @param string $message the commit message
     * @return array
     */
    protected function makeCommitList($sha, $message = 'a commit')
    {
        return [[
            'sha' => $sha,
            'commit' => [
                'message' => $message,
                'author' => ['date' => '2026-09-06T10:00:00Z'],
            ],
        ]];
    }

    /**
     * Read the cache file again
     *
     * @return array
     */
    protected function readCacheFile()
    {
        return json_decode(io_readFile(getCacheName('plugin_gitupdate', '.json')), true);
    }

    /**
     * @return array
     * @see testFilterRepo
     */
    public function provideRepoNames()
    {
        return [
            ['dokuwiki/dokuwiki', 'dokuwiki/dokuwiki'],
            ['some-owner/some.repo_1', 'some-owner/some.repo_1'],
            ['', ''],
            ['dokuwiki', ''],
            ['dokuwiki/dokuwiki/extra', ''],
            ['dokuwiki/dokuwiki x', ''],
            ['../../etc/passwd', ''],
            ['https://evil.example.com/a/b', ''],
            ['evil"><script>alert(1)</script>/x', ''],
            ["dokuwiki/dokuwiki\nX-Injected: 1", ''],
            ["dokuwiki/dokuwiki\n", ''],
            ['dokuwiki/dokuwiki?a=b', ''],
            ['dokuwiki/dokuwiki#f', ''],
            ['user@host/repo', ''],
        ];
    }

    /**
     * The repository name goes into the path of the request URL
     *
     * @param string $input
     * @param string $expect
     * @dataProvider provideRepoNames
     */
    public function testFilterRepo($input, $expect)
    {
        $helper = new helper_plugin_gitupdate_mock();
        $this->assertSame($expect, $this->callInaccessibleMethod($helper, 'filterRepo', [$input]));
    }

    /**
     * @return array
     * @see testFilterSha
     */
    public function provideShaValues()
    {
        return [
            [self::SHA, self::SHA],
            ['1234567', '1234567'],
            ['abcdef0123456789', 'abcdef0123456789'],
            ['', ''],
            ['1', ''],
            ['123456', ''],                 // shorter than 7
            ['ZZZZZZZZ', ''],               // not hexadecimal
            ['ABCDEF1', ''],                // upper case
            [self::SHA . "\n", ''],         // a newline must not pass
            ['<svg onload=alert(1)>', ''],
            [str_repeat('a', 65), ''],      // longer than 64
        ];
    }

    /**
     * Only a commit hash is accepted
     *
     * @param string $input
     * @param string $expect
     * @dataProvider provideShaValues
     */
    public function testFilterSha($input, $expect)
    {
        $helper = new helper_plugin_gitupdate_mock();
        $this->assertSame($expect, $this->callInaccessibleMethod($helper, 'filterSha', [$input]));
    }

    /**
     * @return array
     * @see testIsSameCommit
     */
    public function provideCommitPairs()
    {
        return [
            [self::SHA, self::SHA, true],
            [self::SHA, '1111111', true],           // an abbreviated hash is allowed
            [self::SHA, self::OTHER_SHA, false],
            [self::SHA, '111111', false],           // too short for a safe match
            [self::SHA, '1', false],
            [self::SHA, '', false],
            ['', '', false],
        ];
    }

    /**
     * A short prefix matches many commits. It must not count as a match.
     *
     * @param string $one
     * @param string $two
     * @param bool $expect
     * @dataProvider provideCommitPairs
     */
    public function testIsSameCommit($one, $two, $expect)
    {
        $helper = new helper_plugin_gitupdate_mock();
        $this->assertSame($expect, $this->callInaccessibleMethod($helper, 'isSameCommit', [$one, $two]));
    }

    /**
     * The local commit comes from the git directory
     */
    public function testGetLocalSha()
    {
        $helper = $this->makeHelper();
        $this->assertSame(self::SHA, $helper->getLocalSha());

        // a detached head holds the commit itself
        $helper = $this->makeHelper(['HEAD' => self::OTHER_SHA]);
        $this->assertSame(self::OTHER_SHA, $helper->getLocalSha());

        // a packed ref has no file of its own
        $helper = $this->makeHelper([
            'refs/heads/master' => '',
            'packed-refs' => "# pack-refs with: peeled\n" . self::SHA . " refs/heads/master\n" .
                self::OTHER_SHA . " refs/heads/other\n",
        ]);
        $this->assertSame(self::SHA, $helper->getLocalSha());

        // no git directory
        $helper = $this->makeHelper(['HEAD' => '', 'refs/heads/master' => '']);
        $this->assertSame('', $helper->getLocalSha());

        // a bad value in the ref file
        $helper = $this->makeHelper(['refs/heads/master' => 'not a hash']);
        $this->assertSame('', $helper->getLocalSha());
    }

    /**
     * The branch comes from the setting or from the git directory
     */
    public function testGetBranch()
    {
        global $conf;

        $helper = $this->makeHelper();
        $this->assertSame('master', $helper->getBranch());

        $helper = $this->makeHelper(['HEAD' => 'ref: refs/heads/feature/some-work']);
        $this->assertSame('feature/some-work', $helper->getBranch());

        // a detached head gives no branch
        $helper = $this->makeHelper(['HEAD' => self::SHA]);
        $this->assertSame('', $helper->getBranch());

        // the setting has priority
        $conf['plugin']['gitupdate']['branch'] = 'stable';
        $helper = $this->makeHelper();
        $this->assertSame('stable', $helper->getBranch());
    }

    /**
     * The repository comes from the git config if there is no setting
     */
    public function testGetRepoFromGitConfig()
    {
        $helper = $this->makeHelper();
        $this->assertSame('dokuwiki/dokuwiki', $helper->getRepo());

        $helper = $this->makeHelper([
            'config' => "[remote \"origin\"]\n\turl = https://github.com/some-owner/some.repo\n",
        ]);
        $this->assertSame('some-owner/some.repo', $helper->getRepo());

        // a remote that is not on GitHub gives no repository
        $helper = $this->makeHelper([
            'config' => "[remote \"origin\"]\n\turl = https://gitlab.example.com/a/b.git\n",
        ]);
        $this->assertSame('', $helper->getRepo());
    }

    /**
     * The setting has priority. A bad value stops the check.
     */
    public function testGetRepoFromSetting()
    {
        global $conf;

        $conf['plugin']['gitupdate']['repo'] = 'some-owner/some-fork';
        $helper = $this->makeHelper();
        $this->assertSame('some-owner/some-fork', $helper->getRepo());

        // spaces around the value are removed
        $conf['plugin']['gitupdate']['repo'] = "  some-owner/some-fork\n";
        $helper = $this->makeHelper();
        $this->assertSame('some-owner/some-fork', $helper->getRepo());

        // A bad value gives no repository. The git config is not used instead,
        // because the administrator must see that the setting does not work.
        $conf['plugin']['gitupdate']['repo'] = 'evil"><script>alert(1)</script>/x';
        $helper = $this->makeHelper();
        $this->assertSame('', $helper->getRepo());
    }

    /**
     * The config manager refuses a bad repository name
     */
    public function testConfigManagerRefusesBadRepo()
    {
        $meta = [];
        include DOKU_INC . 'lib/plugins/gitupdate/conf/metadata.php';
        $params = $meta['repo'];
        array_shift($params); // the first item is the class name

        $setting = new SettingString('plugin____gitupdate____repo', $params);
        $setting->initialize('', null, null);
        $this->assertFalse($setting->update('evil"><script>alert(1)</script>/x'));
        $this->assertFalse($setting->update("dokuwiki/dokuwiki\nX-Injected: 1"));
        $this->assertFalse($setting->update("dokuwiki/dokuwiki\n"));
        $this->assertFalse($setting->update('dokuwiki/dokuwiki/extra'));
        $this->assertTrue($setting->update('dokuwiki/dokuwiki'));

        // an empty value stays allowed, it turns the automatic search on
        $setting = new SettingString('plugin____gitupdate____repo', $params);
        $setting->initialize('', 'dokuwiki/dokuwiki', null);
        $this->assertTrue($setting->update(''));
    }

    /**
     * A check needs a checkout, a repository and a branch
     */
    public function testRefreshNeedsAllParts()
    {
        // no git directory
        $helper = $this->makeHelper(['HEAD' => '', 'refs/heads/master' => '']);
        $this->assertFalse($helper->refresh());
        $this->assertCount(0, $helper->requested);

        // no GitHub remote
        $helper = $this->makeHelper(['config' => '']);
        $this->assertFalse($helper->refresh());
        $this->assertCount(0, $helper->requested);

        // a detached head gives no branch
        $helper = $this->makeHelper(['HEAD' => self::SHA]);
        $this->assertFalse($helper->refresh());
        $this->assertCount(0, $helper->requested);
    }

    /**
     * A good answer gives a result that the message can use
     */
    public function testRefreshStoresTheResult()
    {
        $helper = $this->makeHelper();
        $helper->responses = [
            $this->makeCommitList(self::OTHER_SHA, "New feature\n\nA longer text."),
            ['ahead_by' => 7],
        ];

        $this->assertTrue($helper->refresh());
        $this->assertCount(2, $helper->requested);
        foreach ($helper->requested as $url) {
            $this->assertStringStartsWith('https://api.github.com/repos/dokuwiki/dokuwiki/', $url);
        }

        $data = $helper->getStatus();
        $this->assertSame(7, $data['ahead']);
        $this->assertSame(self::OTHER_SHA, $data['sha']);
        $this->assertSame(self::SHA, $data['local']);
        $this->assertSame('master', $data['branch']);
        $this->assertSame('New feature', $data['subject']);
        $this->assertSame(
            'https://github.com/dokuwiki/dokuwiki/compare/' . self::SHA . '...master',
            $data['url']
        );
    }

    /**
     * A wiki that is up to date needs no second request
     */
    public function testRefreshSkipsTheCompareRequest()
    {
        $helper = $this->makeHelper();
        $helper->responses = [$this->makeCommitList(self::SHA)];

        $this->assertTrue($helper->refresh());
        $this->assertCount(1, $helper->requested);
        $this->assertNull($helper->getStatus());
    }

    /**
     * A failed compare request gives an unknown number of new commits
     */
    public function testRefreshWithoutTheCompareAnswer()
    {
        $helper = $this->makeHelper();
        $helper->responses = [$this->makeCommitList(self::OTHER_SHA), null];

        $this->assertTrue($helper->refresh());
        $data = $helper->getStatus();
        $this->assertSame(helper_plugin_gitupdate::AHEAD_UNKNOWN, $data['ahead']);
    }

    /**
     * @return array
     * @see testRefreshRefusesBadShaFromGitHub
     */
    public function provideBadRemoteSha()
    {
        return [
            ['1'],                      // matches almost every commit
            ['111111'],                 // shorter than 7
            ['<svg onload=alert(1)>'],
            ['ZZZZZZZZ'],
            [self::SHA . "\n"],
        ];
    }

    /**
     * A bad hash from GitHub counts as a failed request
     *
     * @param string $sha
     * @dataProvider provideBadRemoteSha
     */
    public function testRefreshRefusesBadShaFromGitHub($sha)
    {
        $helper = $this->makeHelper();
        $helper->responses = [$this->makeCommitList($sha)];

        $this->assertFalse($helper->refresh());
        $this->assertCount(1, $helper->requested); // no compare request follows
        $this->assertNull($helper->getStatus());
    }

    /**
     * The time stamp is in the cache file before the request goes out
     *
     * Two task runners can start at the same time. The second one must not do
     * the same request.
     */
    public function testTheNextCheckIsDelayedBeforeTheRequest()
    {
        $helper = $this->makeHelper();
        $helper->responses = [$this->makeCommitList(self::SHA)];

        $this->assertTrue($helper->isDue());
        $this->assertTrue($helper->refresh());
        $this->assertFalse($helper->dueAtRequest);
    }

    /**
     * A failed request keeps a result that is still applicable
     */
    public function testFailedRequestKeepsAUsableResult()
    {
        $helper = $this->makeHelper();
        $helper->responses = [$this->makeCommitList(self::OTHER_SHA, 'Older news'), ['ahead_by' => 3]];
        $this->assertTrue($helper->refresh());

        // the wiki is not updated, but GitHub does not answer
        $helper = $this->makeHelper();
        $helper->responses = [null];
        $this->assertFalse($helper->refresh());

        $data = $helper->getStatus();
        $this->assertNotNull($data);
        $this->assertSame(3, $data['ahead']);
        $this->assertSame('Older news', $data['subject']);
        $this->assertGreaterThan(0, $this->readCacheFile()['ts']);

        // the next task runner does not repeat the request
        $this->assertFalse($this->makeHelper()->isDue());
    }

    /**
     * A failed request drops a result that belongs to a different commit
     */
    public function testFailedRequestDropsAStaleResult()
    {
        $helper = $this->makeHelper();
        $helper->responses = [$this->makeCommitList(self::OTHER_SHA, 'Old news'), ['ahead_by' => 3]];
        $this->assertTrue($helper->refresh());

        // the wiki is updated, then GitHub does not answer
        $helper = $this->makeHelper(['refs/heads/master' => self::OTHER_SHA]);
        $helper->responses = [null];
        $this->assertTrue($helper->isDue());
        $this->assertFalse($helper->refresh());

        $data = $this->readCacheFile();
        $this->assertSame(self::OTHER_SHA, $data['local']);
        $this->assertSame(0, $data['ahead']);
        $this->assertNull($helper->getStatus());

        // the next task runner does not repeat the request
        $this->assertFalse($this->makeHelper(['refs/heads/master' => self::OTHER_SHA])->isDue());
    }

    /**
     * A result for a different commit gives no message
     */
    public function testStatusIsOnlyForTheCheckedCommit()
    {
        $helper = $this->makeHelper();
        $helper->responses = [$this->makeCommitList(self::OTHER_SHA), ['ahead_by' => 2]];
        $this->assertTrue($helper->refresh());
        $this->assertNotNull($helper->getStatus());

        // the administrator pulls the new commits
        $helper = $this->makeHelper(['refs/heads/master' => self::OTHER_SHA]);
        $this->assertNull($helper->getStatus());
        $this->assertTrue($helper->isDue());
    }

    /**
     * A new check comes after the interval
     */
    public function testIsDueAfterTheInterval()
    {
        global $conf;
        $conf['plugin']['gitupdate']['interval'] = 24;

        // there is no result yet
        $this->assertTrue($this->makeHelper()->isDue());

        $helper = $this->makeHelper();
        $helper->responses = [$this->makeCommitList(self::SHA)];
        $this->assertTrue($helper->refresh());
        $this->assertFalse($this->makeHelper()->isDue());

        // move the last check into the past
        $data = $this->readCacheFile();
        $data['ts'] = time() - (25 * 60 * 60);
        io_saveFile(getCacheName('plugin_gitupdate', '.json'), json_encode($data));
        $this->assertTrue($this->makeHelper()->isDue());

        // without a checkout there is nothing to check
        $this->assertFalse($this->makeHelper(['HEAD' => '', 'refs/heads/master' => ''])->isDue());
    }

    /**
     * The read stays in the git directory
     */
    public function testReadGitFileRefusesOtherPaths()
    {
        $helper = new helper_plugin_gitupdate_mock(); // the real readGitFile()
        foreach (
            [
                '../conf/local.php',
                '../../etc/passwd',
                'refs/../../conf/local.php',
                "HEAD\0../conf/local.php",
            ] as $path
        ) {
            $this->assertSame('', $this->callInaccessibleMethod($helper, 'readGitFile', [$path]), $path);
        }

        // an absolute path stays below the git directory
        $this->assertSame('', $this->callInaccessibleMethod($helper, 'readGitFile', ['/etc/passwd']));

        if (!file_exists(DOKU_INC . '.git/HEAD')) {
            $this->markTestSkipped('this is no git checkout');
        }
        $this->assertSame(
            trim(file_get_contents(DOKU_INC . '.git/HEAD')),
            $this->callInaccessibleMethod($helper, 'readGitFile', ['HEAD'])
        );
    }

    /**
     * A branch name can contain a slash. The slash stays a slash in the URL.
     */
    public function testBranchNamesInTheUrl()
    {
        $helper = $this->makeHelper([
            'HEAD' => 'ref: refs/heads/feature/a b&c',
            'refs/heads/feature/a b&c' => self::SHA,
        ]);
        $helper->responses = [$this->makeCommitList(self::OTHER_SHA), ['ahead_by' => 1]];
        $this->assertTrue($helper->refresh());

        $this->assertStringEndsWith('/commits?per_page=1&sha=feature%2Fa%20b%26c', $helper->requested[0]);
        $this->assertStringContainsString('...feature/a%20b%26c', $helper->requested[1]);
        $this->assertStringEndsWith('...feature/a%20b%26c', $helper->getStatus()['url']);
    }
}
