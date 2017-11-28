<?php
/*
 * Version 0.1. Use with your own risk.
 *
TODO:
- Make this a plugin
- Add view to Admin to see changes
- Force command, to compress image even it's already compressed
- Currently not working, if there's scands on filenames (åöä)
*/

use Aws\CloudFront\CloudFrontClient;
use Aws\Common\Credentials\Credentials;

$plugin_dir = ABSPATH . 'wp-content/plugins/amazon-web-services/';
include_once("vendors/s3.php");
require_once("vendors/Tinify/lib/Tinify/Exception.php");
require_once("vendors/Tinify/lib/Tinify/ResultMeta.php");
require_once("vendors/Tinify/lib/Tinify/Result.php");
require_once("vendors/Tinify/lib/Tinify/Source.php");
require_once("vendors/Tinify/lib/Tinify/Client.php");
require_once("vendors/Tinify/Tinify.php");

if (!defined('WP_CLI')) {
    echo "This script can only run as a wp-cli command\n";
    return;
}

class Compress_Images extends WP_CLI_Command {
    private $aws_access_key = "";
    private $aws_secret_key = "";
    private $aws_bucket     = "";
    private $aws_region     = "";

    function __construct() {
        $this->create_database_table();
        $this->aws_access_key          = getenv("AWS_ACCESS_KEY_ID");
        $this->aws_secret_key          = getenv("AWS_SECRET_ACCESS_KEY");
        $this->aws_bucket              = getenv("AWS_BUCKET");
        $this->aws_region              = getenv("AWS_REGION");
        $this->cloudfront_distribution = getenv('CLOUDFRONT_DISTRIBUTION');
        $this->tinypng_api_key         = getenv("TINYPNG_API_KEY");
    }

    /* This function will create metafields which are helping in sorting different post types by date */
    public function compress_single(array $args = [], array $options = []) {
        if(!$this->check_all_envs_are_set()) {
            return false;
        }

        foreach ($args as $key => $image_id) {
            $this->compress_image($image_id);
        }

    }


    public function compress_all_images(array $args = [], array $options = []) {
        if(!$this->check_all_envs_are_set()) {
            return false;
        }

        $sleep_time = 0.2; //in seconds //TODO: add parameter later
        if(!empty($options['sleep_time'])) {
            $sleep_time = $options['sleep_time'];
        }

        //Get all jpgs and png
        $query_images_args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg','image/png','image/jpg'],
            'post_status'    => 'inherit',
            'posts_per_page' => - 1
        );

        $query_images = new WP_Query( $query_images_args );
        $images = $query_images->posts;

        echo sizeof($images) . " images found \n";

        //get all elements, which need compression
        $images_compressed = 0;
        foreach($images as $image) {
            echo $image->ID . "\n";
            $this->compress_image($image->ID);
            sleep($sleep_time);
        }

    }


    // Print status of images
    public function status() {
        if(!$this->check_all_envs_are_set()) {
            return;
        }

        //Get all jpgs and png
        $query_images_args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg','image/png','image/jpg'],
            'post_status'    => 'inherit',
            'posts_per_page' => - 1
        );

        $query_images = new WP_Query( $query_images_args );
        $images = $query_images->posts;
        echo sizeof($images) . " images (png/jpg/jpeg) found \n";

        //get all elements, which need compression
        $images_which_need_compression = 0;
        $images_compressed = 0;
        foreach($images as $image) {
            $metadata = wp_get_attachment_metadata($image->ID);

            if(!empty($metadata['sizes'])) {
                $sizes = $metadata['sizes'];
                foreach($sizes as $key => $size) {
                    if(!$this->check_if_already_compressed($image->ID, $key)){
                        $images_which_need_compression++;
                    } else {
                        $images_compressed++;
                    }
                }
            }
        }

        echo $images_compressed . " image sizes compressed. \n";
        echo $images_which_need_compression . " image sizes needs compression \n";

    }


    public function image_sizes(array $args = [], array $options = []) {
        $image_id = $args[0];
        $metadata = wp_get_attachment_metadata($image_id);
        var_dump($metadata['sizes']);
    }


    private function check_all_envs_are_set() {
        if($this->aws_access_key == "") {
            echo "AWS_ACCESS_KEY -env is not set \n";
            return false;
        }
        if($this->aws_secret_key == "") {
            echo "AWS_SECRET_KEY -env is not set \n";
            return false;
        }
        if($this->aws_bucket == "") {
            echo "AWS_BUCKET -env is not set \n";
            return false;
        }
        if($this->aws_region == "") {
            echo "AWS_REGION -env is not set \n";
            return false;
        }
        if($this->cloudfront_distribution == "") {
            echo "CLOUDFRONT_DISTRIBUTION -env is not set \n";
            return false;
        }
        if($this->tinypng_api_key == "") {
            echo "TINPYPNG_API_KEY -env is not set \n";
            return false;
        }
        return true;
    }



    private function compress_image($image_id) {

        if(!$this->is_supported_image($image_id)) {
            echo "Not a supported image. Skipping... \n";
            return false;
        }

        $offload_options = get_option("tantan_wordpress_s3"); //place where WP Offload S3 -plugin settings are saved
        $bucket_domain = $offload_options['cloudfront']; #TODO: get default, if cloudfront is not in use
        $bucket_domain = "https://" . $bucket_domain . "/"; #TODO: what if not https?

        $metadata = wp_get_attachment_metadata($image_id);
        $s3_metadata = get_post_meta($image_id, "amazonS3_info");
        $s3_file_key = $s3_metadata[0]['key'];
        $s3_file_path = $bucket_domain . $s3_file_key;
        $s3_directory_path = dirname($s3_file_path); //path without filename

        //Compress all image sizes, original images is not compressed but saved for backup
        \Tinify\setKey($this->tinypng_api_key);
        $sizes = $metadata['sizes'];

        foreach($sizes as $key => $size) {
            //check if already compressed
            if($this->check_if_already_compressed($image_id, $key)) {
                echo "Image ". $image_id. ", size '" . $key . "' already compressed, skipping... \n";
                continue;
            }

            $s3_file_path = $s3_directory_path . "/" . $size['file'];
            $s3_file_key = str_replace($bucket_domain, "", $s3_file_path);

            $info = $this->get_file_info($s3_file_key);
            $original_filesize = $info['size'];

            //Compress file and save to S3
            try {
                echo "compressing file: " . $s3_file_key . "\n";
                $source = \Tinify\fromUrl($s3_file_path);
                $source->preserve("copyright", "creation", "location");

                $source->store(array(
                    "service" => "s3",
                    "aws_access_key_id" => $this->aws_access_key,
                    "aws_secret_access_key" => $this->aws_secret_key,
                    "region" => $this->aws_region,
                    "path" => $this->aws_bucket . "/" . $s3_file_key
                ));
            } catch (Exception $e) {
                echo 'Error while compressing image: ',  $e->getMessage(), "\n";
            }

            //Invalidate Cloudfront
            try {
                $this->invalidate_cloudfront("/". $s3_file_key);
            } catch(Exception $e) {
                echo 'Error while invalidating cloudfront: ',  $e->getMessage(), "\n";
            }

            //check new filesize
            $info = $this->get_file_info($s3_file_key);
            $new_filesize = $info['size'];

            $this->save_info_to_database(
                $image_id,
                $key,
                true,
                $original_filesize,
                $new_filesize
            );

            echo $s3_file_key . " compresssed and cache invalidated" . "\n";
            echo "savings: " . ( ($original_filesize - $new_filesize) / 1000000) . " mb \n";
        }

    }


    private function is_supported_image($post_id) {
        $supported_mime_types = array("image/jpg","image/png","image/jpeg");
        $post_data = get_post($post_id);
        $mime_type = $post_data->post_mime_type;
        if(in_array($mime_type, $supported_mime_types)) {
            return true;
        }
        return false;
    }


    private function save_info_to_database($attachment_id, $image_size, $compressed, $original_filesize, $compressed_filesize) {
        global $wpdb;
        $table_name = $wpdb->prefix . "compressed_images";
        $savings = $original_filesize - $compressed_filesize;
        $time = current_time( "mysql", 1);

        $result = $wpdb->insert($table_name,
            array(
                'time'                => $time,
                'post_id'             => $attachment_id,
                'image_size'          => $image_size,
                'compressed'          => $compressed,
                'original_filesize'   => $original_filesize,
                'compressed_filesize' => $compressed_filesize,
                'savings'             => $savings
            )
        );

    }


    private function invalidate_cloudfront($path_to_invalidate) {
        $credentials = new Credentials($this->aws_access_key, $this->aws_secret_key);

        //invalidate cloudfront
        $client = CloudFrontClient::factory(array(
            'credentials' => $credentials
        ));

        $invalidation = array(
            // DistributionId is required
            'DistributionId' => $this->cloudfront_distribution,
            // Paths is required
            'Paths' => array(
                // Quantity is required
                'Quantity' => 1,
                'Items' => array($path_to_invalidate),
            ),

            // CallerReference is required
            'CallerReference' => time(),
        );

        $result = $client->createInvalidation($invalidation);
    }


    private function upload_to_s3($image_to_upload, $s3_file_key) {
        $s3 = new S3($this->aws_access_key, $this->aws_secret_key);
        $s3->putObjectFile($image_to_upload, $this->aws_bucket, $s3_file_key, S3::ACL_PUBLIC_READ);
    }


    private function get_file_info($s3_file_key) {;
        $s3 = new S3($this->aws_access_key, $this->aws_secret_key);
        return $s3->getObjectInfo($this->aws_bucket, $s3_file_key);
    }


    /*
     * TODO: Not working...
     */
    private function get_bucket_location() {
        $s3 = new S3($this->aws_access_key, $this->aws_secret_key);
        return $s3->getBucketLocation();
    }


    private function check_if_already_compressed($image_id, $image_size) {
        global $wpdb;
        $table_name = $wpdb->prefix . "compressed_images";

        $row = $wpdb->get_row("SELECT * FROM $table_name WHERE post_id = $image_id AND image_size = '$image_size'");

        if(empty($row)) {
            return false;
        } else {
            return $row->compressed;
        }
    }


    private function create_database_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . "compressed_images";
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
          id mediumint(9) NOT NULL AUTO_INCREMENT,
          time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
          post_id mediumint(9) NOT NULL,
          image_size tinytext NOT NULL,
          original_filesize mediumint(9) NOT NULL,
          compressed_filesize mediumint(9) NOT NULL,
          savings mediumint(9) NOT NULL,
          compressed boolean NOT NULL,
          PRIMARY KEY (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        $response = dbDelta($sql);
    }
}

\WP_CLI::add_command('images', 'Compress_Images');
