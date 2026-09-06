<?php

/**
 * Options for the config manager
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Große <mic.grosse+gitupdate@googlemail.com>
 */

$meta['repo'] = ['string', '_pattern' => '#^([\w.-]+/[\w.-]+)?\z#'];
$meta['branch'] = ['string'];
$meta['interval'] = ['numeric', '_min' => 1];
