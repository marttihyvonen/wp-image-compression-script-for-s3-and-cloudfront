<?php
/*
 * Plugin Name: WP image compression script for S3 and Cloudfront
 * Version: 0.1
 * Plugin URI: http://www.marttihyvonen.com
 * Description: Create wp cli script for optimizing images in S3 & Cloudfront environment using TinyPNG API. Need amazon-web-services-plugin and WP Offload S3 -plugin
 * Author: Martti Hyvönen
 * Author URI: http://www.marttihyvonen.com
 * Requires at least: 4.8.3
 * Tested up to: 4.8.3
 *
 * Text Domain: wp-image-compression-script-for-s3-and-cloudfront
 * Domain Path: /lang/
 *
 * @package WordPress
 * @author Hugh Lashbrooke
 * @since 1.0.0
 */
if ( defined('WP_CLI') && WP_CLI ) {
    require_once 'wp-cli-script.php';
}
