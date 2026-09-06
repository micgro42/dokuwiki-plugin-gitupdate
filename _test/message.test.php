<?php

/**
 * Tests for the message of the gitupdate plugin
 *
 * The commit subject, the branch name and the date come from GitHub or from a
 * setting. The message must not make HTML from them.
 *
 * @group plugin_gitupdate
 * @group plugins
 */
class action_plugin_gitupdate_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['gitupdate'];

    /**
     * Make a check result
     *
     * @param array $data fields that replace the default ones
     * @return array
     */
    protected function makeData($data = [])
    {
        return array_merge([
            'ts' => time(),
            'local' => str_repeat('1', 40),
            'repo' => 'dokuwiki/dokuwiki',
            'branch' => 'master',
            'ahead' => 3,
            'url' => 'https://github.com/dokuwiki/dokuwiki/compare/aaaaaaa...master',
            'sha' => str_repeat('2', 40),
            'date' => '2026-09-06T10:00:00Z',
            'subject' => 'A new commit',
        ], $data);
    }

    /**
     * Make the message
     *
     * @param array $data the check result
     * @return string HTML
     */
    protected function buildMessage($data)
    {
        return $this->callInaccessibleMethod(new action_plugin_gitupdate(), 'buildMessage', [$data]);
    }

    /**
     * Find the elements and the attributes of the message
     *
     * @param string $html
     * @return array the element names, the attribute names and the text
     */
    protected function parse($html)
    {
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadHTML('<div>' . $html . '</div>', LIBXML_NOERROR));

        $elements = [];
        $attributes = [];
        foreach ((new DOMXPath($doc))->query('//*') as $element) {
            $elements[$element->nodeName] = true;
            foreach ($element->attributes as $attribute) {
                $attributes[$attribute->nodeName] = true;
            }
        }

        return [array_keys($elements), array_keys($attributes), $doc->textContent];
    }

    /**
     * The message shows the number of new commits
     */
    public function testMessageForManyCommits()
    {
        $html = $this->buildMessage($this->makeData(['ahead' => 12]));
        $this->assertStringContainsString('12 new commits', $html);
        $this->assertStringContainsString('<code>master</code>', $html);
        $this->assertStringContainsString('A new commit', $html);
        $this->assertStringContainsString('2026-09-06', $html);
        $this->assertStringNotContainsString('10:00:00', $html); // only the date
    }

    /**
     * One commit gets its own text
     */
    public function testMessageForOneCommit()
    {
        $html = $this->buildMessage($this->makeData(['ahead' => 1]));
        $this->assertStringContainsString('1 new commit on', $html);
        $this->assertStringNotContainsString('new commits', $html);
    }

    /**
     * A failed compare request gives a message without a number
     */
    public function testMessageForAnUnknownCount()
    {
        $html = $this->buildMessage(
            $this->makeData(['ahead' => helper_plugin_gitupdate::AHEAD_UNKNOWN])
        );
        $this->assertStringContainsString('new commits on', $html);
        $this->assertStringNotContainsString('-1', $html);
    }

    /**
     * The hash replaces a commit message that is empty
     */
    public function testMessageWithoutASubject()
    {
        $data = $this->makeData(['subject' => '']);
        $html = $this->buildMessage($data);
        $this->assertStringContainsString($data['sha'], $html);
    }

    /**
     * There is no link if there is no comparison
     */
    public function testMessageWithoutALink()
    {
        [$elements] = $this->parse($this->buildMessage($this->makeData(['url' => ''])));
        $this->assertNotContains('a', $elements);
    }

    /**
     * The link goes to GitHub and opens in a new tab
     */
    public function testTheLink()
    {
        $html = $this->buildMessage($this->makeData());
        $this->assertStringContainsString(
            '<a href="https://github.com/dokuwiki/dokuwiki/compare/aaaaaaa...master"',
            $html
        );
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    /**
     * @return array
     * @see testFieldsMakeNoHtml
     */
    public function provideHostileData()
    {
        return [
            'subject' => [['subject' => '<script>alert(1)</script>']],
            'branch' => [['branch' => 'feat/"><img src=x onerror=alert(1)>']],
            'date' => [['date' => '<b>2026-09-06</b>']],
            'sha as subject' => [['subject' => '', 'sha' => '"><svg onload=alert(1)>']],
            'url' => [['url' => 'javascript:alert(1)"><iframe src=x>']],
            'all' => [[
                'subject' => '</code><script>alert(1)</script>',
                'branch' => '"><img src=x onerror=alert(1)>',
                'date' => '<b>2026</b>',
                'url' => '" onmouseover="alert(1)',
            ]],
        ];
    }

    /**
     * No field can make an element or an event handler
     *
     * @param array $data fields that replace the default ones
     * @dataProvider provideHostileData
     */
    public function testFieldsMakeNoHtml($data)
    {
        [$elements, $attributes] = $this->parse($this->buildMessage($this->makeData($data)));

        foreach (['script', 'img', 'svg', 'iframe', 'b', 'object', 'embed'] as $name) {
            $this->assertNotContains($name, $elements, "the data made a <$name> element");
        }
        foreach ($attributes as $name) {
            $this->assertStringStartsNotWith('on', $name, "the data made the attribute $name");
        }
        $this->assertEquals(['div', 'strong', 'code', 'a'], array_intersect(
            ['div', 'strong', 'code', 'a'],
            $elements
        ));
    }

    /**
     * The payload stays text, it is not lost
     */
    public function testHostileDataStaysVisibleAsText()
    {
        [, , $text] = $this->parse($this->buildMessage($this->makeData([
            'subject' => '<script>alert(1)</script>',
            'branch' => 'feat/"><img src=x onerror=alert(1)>',
        ])));

        $this->assertStringContainsString('<script>alert(1)</script>', $text);
        $this->assertStringContainsString('feat/"><img src=x onerror=alert(1)>', $text);
    }
}
