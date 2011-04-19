<?php

function pte_json_encode($mixed){
   if ( function_exists('json_encode') ){
      die( json_encode($mixed) );
   }
   else {
      pte_error( "json_encode not available, upgrade your php" );
   }
}

/**
 * Validation functions
 *
 * Return if the GET/POST data is valid
 * pte-error/die if invalid
 */
function validate_image_data_inputs($id, $size){
   if ( !is_numeric( $id ) )
      pte_error("Parameter 'id' is not numeric");

   $fullsizepath = get_attached_file( $id );
   $path_information = image_get_intermediate_size($id, $size);

   $size_information = pte_get_alternate_sizes(true);
   if (! array_key_exists( $size, $size_information ) ){
      pte_error("Invalid size: {$size}");
   }
   return compact( 'fullsizepath'
      , 'path_information'
      , 'size_information' 
   );
}

function validate_resize_inputs( $id, $thumb_size, $x, $y, $w, $h ){
   // Verifies that the user has proper access and intent
   check_ajax_referer("pte-{$id}-{$thumb_size}");

   //Check that the parameters are digits
   if ( !( is_numeric($id) &&
        is_numeric($x) &&
        is_numeric($y) &&
        is_numeric($w) &&
        is_numeric($h) ) ){
           pte_error("One of 'id', 'x', 'y', 'w', or 'h' is not numeric");
        }

   // Check the POST
   $id = (int) $id;
   if ( !$post =& get_post( $id ) )
      pte_error("Invalid id: {$id}");

   $file = get_attached_file( $id );
   $image = wp_load_image( $file );

   $size_information = pte_get_alternate_sizes(true);
   if (! array_key_exists( $thumb_size, $size_information ) ){
      pte_error("Invalid size: {$thumb_size}");
   }

   if (! $path_information = image_get_intermediate_size($id, $thumb_size)){
      pte_error("Invalid image: {$id} {$thumb_size}");
   }

   if ( !is_resource( $image ) )
      pte_error("Error loading image");

   $size = @getimagesize( $file );
   if ( !$size )
      pte_error("Could not read image size");

   list($orig_w, $orig_h, $orig_type) = $size;

   // Error checking that the src is big enough to go into dst?
   if ( 
      $x < 0 ||
      $y < 0 ||
      $x + $w > $orig_w ||
      $y + $h > $orig_h ||
      $w <= 0 || 
      $h <= 0 ){
      pte_error("Invalid input parameters: {$x} {$y} {$w} {$h}");
   }
   $array = compact('id'
      , 'post'
      , 'file'
      , 'image'
      , 'size_information'
      , 'path_information'
      , 'orig_w'
      , 'orig_h'
      , 'orig_type'
   );
   return $array;
}
/** END VALIDATION **/

function pte_get_alternate_sizes($return_php = false){
   global $_wp_additional_image_sizes;
   $sizes = array();
   foreach (get_intermediate_image_sizes() as $s){
      if ( isset( $_wp_additional_image_sizes[$s]['width'] ) ) // For theme-added sizes
         $width = intval( $_wp_additional_image_sizes[$s]['width'] );
      else                                                     // For default sizes set in options
         $width = get_option( "{$s}_size_w" );

      if ( isset( $_wp_additional_image_sizes[$s]['height'] ) ) // For theme-added sizes
         $height = intval( $_wp_additional_image_sizes[$s]['height'] );
      else                                                      // For default sizes set in options
         $height = get_option( "{$s}_size_h" );

      if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) ) // For theme-added sizes
         $crop = intval( $_wp_additional_image_sizes[$s]['crop'] );
      else                                                      // For default sizes set in options
         $crop = get_option( "{$s}_crop" );
      $sizes[$s] = array(
         'width'  => $width,
         'height' => $height,
         'crop'   => $crop
      );
   }

   if ( $return_php ) return $sizes;
   pte_json_encode( array( 'sizes' => $sizes ) );
}

function pte_get_image_data($id, $size){

   // Validate the inputs
   // Create and return:
   //    $fullsizepath
   //    $path_information
   //    $size_information 
   extract( validate_image_data_inputs( $id, $size ) );

   // Get/Create nonce
   //   - This key grants limited 24 hour access to modify the attachment $id's $size
   $nonce = wp_create_nonce("pte-{$id}-{$size}");

   if ( $path_information && 
      @file_exists(dirname($fullsizepath)."/".$path_information['file']))
   {
      $path_information['nonce'] = $nonce;
      //$path_information['debug'] = "Finished without regenerating image";
      pte_json_encode($path_information);
   }

   // We don't really care how it gets generated, just that it is...
   // see ajax-thumbnail-rebuild plugin for inspiration
   if ( FALSE !== $fullsizepath && @file_exists($fullsizepath) ) {
      // Create the image and update the wordpress metadata
      $resized = image_make_intermediate_size( $fullsizepath, 
         $size_information[$size]['width'], 
         $size_information[$size]['height'],
         $size_information[$size]['crop']
      );
      if ($resized){
         $metadata = wp_get_attachment_metadata($id);
         $metadata['sizes'][$size] = $resized;
         wp_update_attachment_metadata( $id, $metadata);
      }
   }

   // Finish how we started
   $path_information = image_get_intermediate_size($id, $size);
   if ($path_information){
      $path_information['nonce'] = $nonce;
      pte_json_encode($path_information);
   }
   else {
      //print("{\"error\":\"Couldn't find metadata for image: $id\"}");
      pte_error("Couldn't find metadata for image");
   }
}

function pte_error($message){
   die("{\"error\":\"{$message}\"}");
}

/* 
 * See wordpress: wp-includes/media.php for image_resize
 *
 * TODO: Break into smaller pieces
 * TODO: Test saving POST META
 */
function pte_resize_img($id, $thumb_size, $x, $y, $w, $h, $save = true){
   // Check your inputs...
   // Also creates and returns:
   //    $post
   //    $file
   //    $image
   //    $size_information
   //    $path_information
   //    $orig_w
   //    $orig_h
   //    $orig_type
   //extract(validate_resize_inputs( $id, $thumb_size, $x, $y, $w, $h ));
   $validation = validate_resize_inputs( $id, $thumb_size, $x, $y, $w, $h );
   extract( $validation );

   // Set the output (destination) information
   $dst_x = 0;
   $dst_y = 0;

   // ==============================
   // Get Destination width & height
   // ==============================
   // When the crop isn't set the biggest dimension is accurate, 
   // but the other dimension is wrong
   if ($size_information[$thumb_size]['crop']){
      $dst_w = $size_information[$thumb_size]['width'];
      $dst_h = $size_information[$thumb_size]['height'];
   }
   // Crop isn't set so the height / width should be based on the biggest side
   // Filename changes
   // Update wp_attachment_metadata with the correct file/width/height
   else if ($w > $h){
      $dst_w = $size_information[$thumb_size]['width'];
      $dst_h = round( ($dst_w/$w) * $h, 0, PHP_ROUND_HALF_DOWN );
   }
   else {
      $dst_h = $size_information[$thumb_size]['height'];
      $dst_w = round( ($dst_h/$h) * $w, 0, PHP_ROUND_HALF_DOWN );
   }
   // ==============================


   // ================
   // Get the filename
   // ================
   // See image_resize function in wp-includes/media.php to follow the same conventions
   $info = pathinfo($file);
   $dir = $info['dirname'];
   $ext = $info['extension'];
   $name = wp_basename($file, ".$ext");
   $suffix = "{$dst_w}x{$dst_h}-pte";
   $destfilename = "{$dir}/{$name}-{$suffix}.{$ext}";
   // ================

   // Set the input information
   $src_x = $x;
   $src_y = $y;
   $src_w = $w;
   $src_h = $h;

   // Now let's get down to business...
   $newimage = wp_imagecreatetruecolor( $dst_w, $dst_h );

   // Save the conversion data so if a batch script (a la ajax-thumbnail-rebuild)
   // that we can find what the scale/crop area and rebuild using it.
   if ($save){
      $data = get_post_meta( $post->ID, PTE_POST_DATA, true );
      $data[$thumb_size] = compact( 'dst_x', 'dst_y', 'dst_w', 'dst_h'
                                  , 'src_x', 'src_y', 'src_w', 'src_h'
      );
      update_post_meta($post->ID, PTE_POST_DATA, $data);
   }

   imagecopyresampled( $newimage, $image
       , $dst_x, $dst_y, $src_x, $src_y
       , $dst_w, $dst_h, $src_w, $src_h );

   // convert from full colors to index colors, like original PNG.
   if ( IMAGETYPE_PNG == $orig_type && function_exists('imageistruecolor') && !imageistruecolor( $image ) )
      imagetruecolortopalette( $newimage, false, imagecolorstotal( $image ) );

   // we don't need the original in memory anymore
   imagedestroy( $image );

   if ( IMAGETYPE_GIF == $orig_type ) {
      if ( !imagegif( $newimage, $destfilename ) )
         //return new WP_Error('resize_path_invalid', __( 'Resize path invalid' ));
         pte_error("Resize path invalid");
   } 
   elseif ( IMAGETYPE_PNG == $orig_type ) {
      if ( !imagepng( $newimage, $destfilename ) )
         //return new WP_Error('resize_path_invalid', __( 'Resize path invalid' ));
         pte_error("Resize path invalid");
   } 
   else {
      // all other formats are converted to jpg
      //if ( !imagejpeg( $newimage, $destfilename, apply_filters( 'jpeg_quality', $jpeg_quality, 'image_resize' ) ) )
      if ( !imagejpeg( $newimage, $destfilename, 90) )
         //return new WP_Error('resize_path_invalid', __( 'Resize path invalid' ));
         pte_error("Resize path invalid: " . $destfilename);
   }

   imagedestroy( $newimage );

   // Set correct file permissions
   $stat = stat( dirname( $destfilename ));
   $perms = $stat['mode'] & 0000666; //same permissions as parent folder, strip off the executable bits
   @ chmod( $destfilename, $perms );

   // Update attachment metadata
   $metadata = wp_get_attachment_metadata($id);
   $metadata['sizes'][$thumb_size] = array( 'file' => "{$name}-{$suffix}.{$ext}"
       , 'width' => $dst_w
       , 'height' => $dst_h 
   );
   wp_update_attachment_metadata( $id, $metadata);
   
   $path_information = image_get_intermediate_size($id, $thumb_size);
   pte_json_encode( array( "url" => $path_information['url'] ) );
}

