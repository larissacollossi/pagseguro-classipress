<?php

/*
Plugin Name: Pagseguro Classipress
Description: A ClassiPress Gateway Plugin for Pagseguro
Version: 1.0
Author: Bruno Rodrigues
Author URI: http://www.idx.is
*/

add_action( 'init', 'pagseguro_setup' );

function pagseguro_setup() {
	include 'pagseguro-classipress-gateway.php';
}