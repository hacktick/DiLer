<?php
/**
 * Options for the authjoomla plugin
 *
 * @author Andreas Gohr <dokuwiki@cosmocode.de>
 * @author  Stephan Thamm <stephan@innovailable.eu>
 */

$meta['debug'] = array('onoff');
$meta['dsn'] = array('string', '_caution' => 'danger');
$meta['user'] = array('string', '_caution' => 'danger');
$meta['pass'] = array('password', '_caution' => 'danger', '_code' => 'base64');
$meta['tableprefix'] = array('string');
$meta['frontendcookie'] = array('string');
$meta['backendcookie'] = array('string');

