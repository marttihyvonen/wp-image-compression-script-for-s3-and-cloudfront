<?php
/*
 * Plugin Name: WP image compression script for S3 and Cloudfront
 * Version: 0.1
 * Plugin URI: https://github.com/marttihyvonen/wp-image-compression-script-for-s3-and-cloufront/
 * Description: Create wp cli script for optimizing images in S3 & Cloudfront environment using TinyPNG API. Need amazon-web-services-plugin and WP Offload S3 -plugin
 * Author: Martti Hyvönen (martti.hyvonen@frantic.com)
 * Requires at least: 4.8.3
 * Tested up to: 4.8.3
 *
 * Text Domain: wp-image-compression-script-for-s3-and-cloudfront
 * Domain Path: /lang/
 *
 * @package WordPress
 * @author Martti Hyvönen
 * @since 0.1
 */
if ( defined('WP_CLI') && WP_CLI ) {
    require_once 'wp-cli-script.php';
}
