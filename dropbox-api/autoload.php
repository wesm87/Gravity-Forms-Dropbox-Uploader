<?php

/**
 * This file registers a new autoload function using spl_autoload_register. 
 *
 * @package Dropbox 
 * @copyright Copyright (C) 2010 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/dropbox-php/wiki/License MIT
 */

/**
 * Autoloader function
 *
 * @param $className string
 * @return void
 */
 
if(!function_exists('Dropbox_autoload')) 
{ 
	function Dropbox_autoload($className) {
	
		if(strpos($className,'Dropbox_')===0) {
	
			include dirname(__FILE__) . '/' . str_replace('_','/',substr($className,8)) . '.php';
	
		}
	
	}
	
	spl_autoload_register('Dropbox_autoload');
}

