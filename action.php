<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * DokuWiki Plugin gitupdate (Action Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <mic.grosse+gitupdate@googlemail.com>
 */
class action_plugin_gitupdate extends ActionPlugin
{
    /** @inheritDoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('INDEXER_TASKS_RUN', 'AFTER', $this, 'checkUpstream');
        $controller->register_hook('DOKUWIKI_STARTED', 'BEFORE', $this, 'showMessage');
    }

    /**
     * Ask GitHub for new commits
     *
     * This runs in the task runner. The request must not delay a page.
     *
     * @param Event $event event object
     * @param mixed $param the parameter from the registration
     * @return void
     */
    public function checkUpstream(Event $event, $param)
    {
        global $conf;
        if (!$conf['updatecheck']) return;

        /** @var helper_plugin_gitupdate $helper */
        $helper = plugin_load('helper', 'gitupdate');
        if (!$helper instanceof helper_plugin_gitupdate) return;
        if (!$helper->isDue()) return;

        $helper->refresh();

        // one task per task runner call is sufficient
        $event->stopPropagation();
    }

    /**
     * Show a message to admins if the tracked branch has new commits
     *
     * @param Event $event event object
     * @param mixed $param the parameter from the registration
     * @return void
     */
    public function showMessage(Event $event, $param)
    {
        global $conf;
        global $INFO;
        if (!$conf['updatecheck']) return;
        // With ACL off there are no admins. Then the message goes to everybody,
        // the same as the core update message does.
        if ($conf['useacl'] && empty($INFO['isadmin'])) return;

        /** @var helper_plugin_gitupdate $helper */
        $helper = plugin_load('helper', 'gitupdate');
        if (!$helper instanceof helper_plugin_gitupdate) return;

        $data = $helper->getStatus();
        if ($data === null) return;

        msg($this->buildMessage($data), 2);
    }

    /**
     * Make the message text
     *
     * @param array $data the check result from the helper
     * @return string HTML
     */
    protected function buildMessage($data)
    {
        $link = '';
        if ($data['url'] !== '') {
            $link = sprintf(
                '<a href="%s" target="_blank" rel="noopener">%s</a>',
                hsc($data['url']),
                $this->getLang('compare')
            );
        }

        $subject = $data['subject'] !== '' ? $data['subject'] : $data['sha'];

        if ($data['ahead'] === helper_plugin_gitupdate::AHEAD_UNKNOWN) {
            $key = 'behind_unknown';
        } elseif ($data['ahead'] === 1) {
            $key = 'behind_one';
        } else {
            $key = 'behind_many';
        }

        return sprintf(
            $this->getLang($key),
            $data['ahead'],
            hsc($data['branch']),
            hsc($subject),
            hsc(substr($data['date'], 0, 10)),
            $link
        );
    }
}
