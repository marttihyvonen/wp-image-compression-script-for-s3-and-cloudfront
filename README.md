# wp-image-compression-script-for-s3-and-cloufront
Creates wp-cli script for optimizing images in S3 &amp; Cloudfront environment using TinyPNG API. Need amazon-web-services-plugin and WP Offload S3 -plugin.

## things you need to do & have before this script works ##

### plugins ###
Plugins needed for this script to run:
Amazon Web Services https://wordpress.org/plugins/amazon-web-services/
WP Offload S3 Lite https://wordpress.org/plugins/amazon-s3-and-cloudfront/

### envs ###
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_BUCKET
AWS_REGION
CLOUDFRONT_DISTRIBUTION
TINYPNG_API_KEY

## commands ###

wp images status

wp images compress single [attachment_id]

wp images compress_all_images

## running wp-cli scripts in heroku & bedrock-on-heroku environment ##

heroku run --app [app_name] ./bedrock-on-heroku/vendor/bin/wp images status
