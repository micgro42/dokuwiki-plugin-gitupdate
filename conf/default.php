<?php

/**
 * Default settings for the gitupdate plugin
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <mic.grosse+gitupdate@googlemail.com>
 */

$conf['repo'] = '';      // empty: read the first GitHub remote from .git/config
$conf['branch'] = '';    // empty: read the current branch from .git/HEAD
$conf['interval'] = 24;  // hours between two checks
