<?php
/*
Plugin Name: WP_Auctions
Plugin URI: http://www.wpauctions.com/download/
Description: Implements the ability to run auctions on your own blog. Once activated, add the widget to your sidebar or add <code>&lt;?php wp_auctions(); ?&gt;</code> to your sidebar.
Version: 3.7
Author: Owen Cutajar & Hyder Jaffari
Author URI: http://www.wpauctions.com/download/
*/

  /* History:
  v0.1 Beta  - OwenC - 29/01/08 - Initial beta release
  v1.0 Free  - OwenC - 21/02/08 - Free public release  
  v3.0 Free  - OwenC - 14/10/14 - Refreshed with premium features
  v3.5.1 Free - HyderJ - 07/05/2015 - Fixed CSS issues
  v3.6 Free  - OwenC - 1/6/2015 - Refreshed with premium features - Embeded auctions
			- OwenC - 15/6/2015 - Rectified a number of warnings - Traced down old bug where notifications not always sent
  v3.6.3 - OwenC - 08/03/2016 - Bug Fixes
  v3.6.5 - HyderJ/OwenC - 14/4/2016 - v4.5 compatibility
  v3.6.5 - OwenC - 1/3/2016 - Switch to wp_mail functionality
  v3.7  - OwenC - 5/4/2019 - Bring in line with latest WP version
*/

//error_reporting (E_ALL ^ E_NOTICE);

// cater for stand-alone calls
if (!function_exists('get_option'))
	require_once('../../../wp-config.php');
 
$wpa_version = "3.7";

// Consts
if (!defined('WPA_PLUGIN_NAME')) {
 
  define ('WPA_PLUGIN_NAME', trim(dirname(plugin_basename(__FILE__)),'/'));
  define ('WPA_PLUGIN_DIR', dirname( plugin_basename( __FILE__ ) ));
  define ('WPA_PLUGIN_URL', plugins_url() . '/' . WPA_PLUGIN_NAME);
   
  define ('WPA_PLUGIN_FILE', 'wp_auctions.php');
  define ('WPA_PLUGIN_FULL_PATH', WPA_PLUGIN_URL . "/" . WPA_PLUGIN_FILE );
  define ('WPA_PLUGIN_RSS', WPA_PLUGIN_FULL_PATH . "?rss" );
  define ('WPA_PLUGIN_STYLE', WPA_PLUGIN_URL . "/styles" );
  define ('WPA_PLUGIN_REQUISITES', WPA_PLUGIN_URL . "/requisites" );  
}

// ensure localisation support
load_plugin_textdomain( 'wpauctions', false, 'wp-auctions/languages' );

define('BID_WIN', __('Congratulations, you are the highest bidder on this item.','wpauctions') );
define('BID_LOSE', __("I'm sorry, but a preceeding bidder has outbid you.",'wpauctions') );
define('BIN_WIN', __("Thanks for buying! Payment instructions have been emailed.",'wpauctions') );

define('POPUP_SIZE', "&height=579&width=755&modal=true");

//---------------------------------------------------
//--------------AJAX CALLPOINTS----------------------
//---------------------------------------------------

if (strstr($_SERVER['PHP_SELF'],WPA_PLUGIN_NAME) && isset($_GET['postauction'])):

  // check security
  check_ajax_referer( "WPA-nonce" );

	// process posted values here
	$auction_id = $_POST['auction_id'];
	$bidder_name = htmlspecialchars(strip_tags(stripslashes($_POST['bidder_name'])), ENT_QUOTES);
	$bidder_email = strip_tags(stripslashes($_POST['bidder_email']));
	$bidder_url = htmlspecialchars(strip_tags(stripslashes($_POST['bidder_url'])), ENT_QUOTES);
	$max_bid = $_POST['max_bid'];
	$BIN_amount = $_POST['BIN_amount'];

   $result = wpa_process_bid( $auction_id, $bidder_name, $bidder_email, $bidder_url, $max_bid, $BIN_amount );
   
    echo $result;
	exit;
endif;

if (strstr($_SERVER['PHP_SELF'],WPA_PLUGIN_NAME) && isset($_GET['queryauction'])):

	global $wpdb;
  
  // thumbnail size is set here
  $thumbnail_size = 25;
  $image_size = 250;
 
	function fail($s) { header('HTTP/1.0 406 Not Acceptable'); die($s);}

  // check security
  check_ajax_referer( "WPA-nonce" );

	// process query string here
	$auction_id = $_POST['auction_ID'];

	// validate input
	if (!is_numeric($auction_id)) // ID not numeric
		fail(__('Invalid Auction ID specified','wpauctions'));
		
    // confirm if auction has ended or not
    check_auction_end($auction_id);

  	// prepare result
  	$table_name = $wpdb->prefix . "wpa_auctions";
  	$strSQL = "SELECT id, name,description,current_price,date_create,date_end,start_price,image_url, '".current_time('mysql',"1")."' < date_end, winner, winning_price, BIN_price, extraimage1, extraimage2, extraimage3, 0.00 as 'next_bid', shipping_price, shipping_to, 'placeholder' as 'otherimages' FROM $table_name WHERE id=".$auction_id;
  	$rows = $wpdb->get_row ($strSQL, ARRAY_N);

  	// send back result
    if (!($rows)) // no records found
       fail(__('Cannot locate auction','wpauctions'));

    // pass image through resizer
    
    
    $temp = $rows[7];
    $rows[7] = wpa_resize ($rows[7],$image_size);
    
    $rows[18] = "";
    // other images could be blank .. in which case, don't resize
    if ($rows[12] != "") {
       $rows[18] = $rows[18].'^'.wpa_resize ($rows[12],$thumbnail_size);
       $rows[12] = wpa_resize ($rows[12],$image_size);
    }
    if ($rows[13] != "") {
       $rows[18] = $rows[18].'^'.wpa_resize ($rows[13],$thumbnail_size);
       $rows[13] = wpa_resize ($rows[13],$image_size);
    }       
    if ($rows[14] != "") { 
       $rows[18] = $rows[18].'^'.wpa_resize ($rows[14],$thumbnail_size);
       $rows[14] = wpa_resize ($rows[14],$image_size);
    }
       
    //. append initial image if we have other images
    if ( $rows[18] != "") $rows[18] = $rows[18] . '^'.wpa_resize ($temp,$thumbnail_size);
        
    // normalise dates
    $rows[4] = date('dS M Y h:i A',strtotime(get_date_from_gmt($rows[4])));
    $rows[5] = date('dS M Y h:i A',strtotime(get_date_from_gmt($rows[5])));

    // insert next increment if not starting price
    if ($rows[3] >= $rows[6]) {
       $rows[15] = number_format($rows[3] + wpa_get_increment($rows[3]), 2, '.', ',');
    } else {
       $rows[15] = $rows[6];
    }

	// prepare results   	
  //  $result_set = implode("|",$rows);
  $result_set = implode("|", $rows);  
        	
    echo $result_set;
	exit;
endif;

if (strstr($_SERVER['PHP_SELF'],WPA_PLUGIN_NAME) && isset($_GET['querybids'])):

	global $wpdb;

	function fail($s) { header('HTTP/1.0 406 Not Acceptable'); die($s);}

  // check security
  check_ajax_referer( "WPA-nonce" );

	// process query string here
	$auction_id = $_POST['auction_ID'];

	// validate input
	if (!is_numeric($auction_id)) // ID not numeric
		fail(__('Invalid Auction ID specified','wpauctions'));
		
	// prepare result
	$table_name = $wpdb->prefix . "wpa_bids";
	$strSQL = "SELECT bidder_name, bidder_url ,date,current_bid_price, bid_type FROM $table_name WHERE auction_id=".$auction_id." ORDER BY current_bid_price DESC, bid_type";
	$rows = $wpdb->get_results ($strSQL, ARRAY_N);

	// send back result
    if (!($rows)) // no records found
       $result_set="";
    else {
//       foreach ($rows as &$row) {
//          $row[2] = date('dS M Y h:i A',strtotime(get_date_from_gmt($row[2]))); // convert dates to WP timezone
//       }

// change above code as it didn't work in PHP 4

         foreach($rows as $i=>$row){
            $row[2] = date('dS M Y h:i A',strtotime(get_date_from_gmt($row[2]))); // convert dates to WP timezone
            // replace the row in the table
            $rows[$i]=$row;
         }
       $result_set = wpa_implode_r("|",$rows);
    }
      	
    echo $result_set;
	exit;
endif;


if (strstr($_SERVER['PHP_SELF'],WPA_PLUGIN_NAME) && isset($_GET['queryother'])):

	global $wpdb;

	function fail($s) { header('HTTP/1.0 406 Not Acceptable'); die($s);}

  // check security
  check_ajax_referer( "WPA-nonce" );

	// process query string here
	$auction_id = $_POST['auction_ID'];

	// validate input
	if (!is_numeric($auction_id)) // ID not numeric
		fail(__('Invalid Auction ID specified','wpauctions'));
		
	// prepare result
	$table_name = $wpdb->prefix . "wpa_auctions";
	$strSQL = "SELECT id,name,image_url,current_price,start_price,0.00 as 'next_bid' FROM $table_name WHERE id <> ".$auction_id." AND '".current_time('mysql',"1")."' < date_end ORDER BY RAND() LIMIT 4";
	$rows = $wpdb->get_results ($strSQL, ARRAY_N);

      foreach($rows as $i=>$row){

        $row[2] = wpa_resize($row[2],50);

            // insert current price
           if ($row[3] >= $row[4]) {
              $row[5] = $row[3];
           } else {
              $row[5] = $row[4];
           }            

         // replace the row in the table
         $rows[$i]=$row;
      }

	// send back result
    if (!($rows)) // no records found
       $result_set="";
    else
       $result_set = wpa_implode_r("|",$rows);
      	
    echo $result_set;
	exit;
endif;

//---------------------------------------------------
//--------------RSS FEED-----------------------------
//---------------------------------------------------
if (strstr($_SERVER['PHP_SELF'],WPA_PLUGIN_NAME) && isset($_GET['rss'])):
header("Content-Type:application/rss+xml");

	global $wpdb;
	global $wpa_version;

  $options = get_option('wp_auctions');
  $currencycode = $options['currencycode'];

	// prepare result
	$table_name = $wpdb->prefix . "wpa_auctions";
	$strSQL = "SELECT * FROM $table_name WHERE '".current_time('mysql',"1")."' < date_end ORDER BY ID desc LIMIT 15";
	$rows = $wpdb->get_results ($strSQL);

$now = date("D, d M Y H:i:s T");

$output = "<?xml version=\"1.0\"?>
            <rss version=\"2.0\">
                <channel>
                    <title>".get_option('blogname')." Auctions</title>
                    <link>". WPA_PLUGIN_RSS . "</link>
                    <description>Auction feed generated by wp_auctions (http://www.wpauctions.com) version ".$wpa_version."</description>
                    <language>en-us</language>
                    <pubDate>$now</pubDate>
                    <lastBuildDate>$now</lastBuildDate>
                    <docs>http://someurl.com</docs>
                    <managingEditor>".get_option('admin_email')."</managingEditor>
                    <webMaster>".get_option('admin_email')."</webMaster>
            ";
            
foreach ($rows as $line)
{
    $output .= "<item><title>".htmlentities($line->name)."</title>
                    <link>".get_bloginfo('wpurl')."?auction_to_show=".$line->id."</link>
                    <description><![CDATA[<img src='".wpa_resize($line->image_url,50)."' align='left'>".htmlentities(strip_tags($line->description))." - Closing: ".date('dS M Y',strtotime($line->date_end))." - Current Bid: ".$currencycode.number_format($line->current_price, 2, '.', ',')." -]]></description>
                </item>";
}
$output .= "</channel></rss>";

    echo $output;
	exit;
endif;

//---------------------------------------------------
//--------------HELPER FUNCTIONS---------------------
//---------------------------------------------------

// helper function for multi-dimensional implode
function wpa_implode_r ($glue, $pieces) {
 $out = "";
 foreach ($pieces as $piece)
  if (is_array ($piece)) $out .= wpa_implode_r ($glue, $piece);
  else                   $out .= $glue.$piece;
 return $out;
}

// helper function to calculate increment based on amount
function wpa_get_increment ($value) {

 $options = get_option('wp_auctions');
 $customincrement = $options['customincrement'];

 if (empty($customincrement)) {
  $out = 0.01;

  if ($value >= 1000) {
     $out = 10;
   } elseif ($value >= 250) {
     $out = 5;
   } elseif ($value >= 50) {
     $out = 2;
   } elseif ($value >= 25) {
     $out = 1;
   } elseif ($value >= 10) {
     $out = 0.50;
   } elseif ($value >= 5) {
     $out = 0.25;
   } elseif ($value >= 1) {
     $out = 0.1;
   } elseif ($value >= 0.5) {
     $out = 0.05;
   }
 } else {
   $out = $customincrement;
 }

 return $out;
}

// helper function to validate email address
function wpa_valid_email($address)
{
	// check an email address is valid
	return filter_var($address, FILTER_VALIDATE_EMAIL);
}


if(!function_exists('file_put_contents')) {
    function file_put_contents($filename, $data, $file_append = false) {

      $fp = fopen($filename, (!$file_append ? 'w+' : 'a+'));
        if(!$fp) {
          trigger_error('file_put_contents cannot write in file.', E_USER_ERROR);
          return;
        }
      fputs($fp, $data);
      fclose($fp);
    }
  }
  
// new resize function .. using WP's built in resizer
function wpa_resize ( $image, $size, $height = 0 ) {

	// resize now done on upload. All we need to do is produce correct image URL

	if (is_numeric($image) || ($image == "")) {

		switch ( $size ) {
		case 250:
			$class = "WPA_popup";
			break;
		case 100:
			$class = "WPA_page";
			break;
		case 125:
			$class = "WPA_widget";
			break;
		default:
			$class = "WPA_thumbnail";
		}

		$thumbnail = wp_get_attachment_image_src ( $image , $class );

		if (empty($thumbnail[0])) {
			$thumb = WPA_PLUGIN_REQUISITES . "/default-$size.png";
		} else {
			$thumb = $thumbnail[0];
		}
	} else {
		$thumb = "ERROR: Image not in media library";
	}

	return $thumb;

	//$options = get_option('wp_auctions_design');
	//$DoNotCrop = htmlspecialchars($options['DoNotCrop'], ENT_QUOTES);
	//$cut = ($DoNotCrop != "Yes");
}

//---------------------------------------------------
//--------------INTERNAL CODE------------------------
//---------------------------------------------------


function wpa_process_bid( $auction_id, $bidder_name, $bidder_email, $bidder_url, $max_bid, $BIN_amount ) {

	global $wpdb;

  //echo "<!-- in code -->";
  
  $result = "";
  $options = get_option('wp_auctions');
  $notify = $options['notify'];
  $title = $options['title'];
  $regonly = $options['regonly'];
  $currencysymbol = $options['currencysymbol'];
  
  // Setup email fields.         
  $emailoptions = get_option('wp_auctions_email');
  
  $bid_type = "user";

	// validate input
	if (!is_numeric($auction_id)): // ID not numeric
		$result = __('Invalid Auction ID specified','wpauctions');
    elseif (trim($bidder_name == '')):  // Bidder name not specified
        $result = __('Bidder name not supplied','wpauctions');
    elseif (trim($bidder_email == '')):  // Bidder email not specified
        $result = __('Bidder email not supplied','wpauctions');
    elseif (!wpa_valid_email($bidder_email)):  // Bidder email not specified
        $result = __('Please supply a valid email address','wpauctions');
    elseif (!is_numeric($max_bid)):  // Bidder email not specified
        $result = __('Your bid value is invalid','wpauctions');
    elseif (($regonly=="Yes") && !is_user_logged_in()): // Bidder needs to be registered and isn't (HACK TEST)
        $result = __('You need to be signed in to place bids','wpauctions');
    endif;
		
    if ($result == '') {
       // If we get this far it means that the input data is completely valid, so sanity check the data

       // Before we start .. confirm if auction has ended or not
       check_auction_end($auction_id);

       // bid is the starting bid on the auction	
       $table_name = $wpdb->prefix . "wpa_auctions";
	     $strSQL = "SELECT winner FROM $table_name WHERE id=".$auction_id;
	     $winner = $wpdb->get_var ($strSQL);          

       if ($winner != "") $result=__("Sorry, this auction is now closed",'wpauctions');

       // Let's also check that the bid is in the right range for the (piggyback staticpage)
  		 $table_name = $wpdb->prefix . "wpa_auctions";
			 $strSQL = "SELECT current_price,start_price,staticpage FROM $table_name WHERE id=".$auction_id;
			 $rows = $wpdb->get_row ($strSQL);

       $staticpage = $rows->staticpage; // (don't need this here, just more efficient)

       if ($rows->start_price > $max_bid) $result=__("Sorry, your bid must exceed the auction start price",'wpauctions');
       if ($rows->current_price >= $max_bid) $result=__("Sorry, your bid must exceed the current bid price",'wpauctions');
       if ($rows->current_price + wpa_get_increment($rows->current_price) > $max_bid) $result=__("Sorry, your bid must exceed",'wpauctions')." ".$currencysymbol.number_format($rows->current_price + wpa_get_increment($rows->current_price), 2, '.', ',');;

       // override bidding process if auction in a "Buy It Now"
       if ($BIN_amount > 0) {      
          $thisbid = $BIN_amount;
          $result = BIN_WIN;

          // close the auction
  			  $table_name = $wpdb->prefix . "wpa_auctions";
	  		  $sql = "UPDATE ".$table_name." SET date_end = '".current_time('mysql',"1")."' WHERE id=".$auction_id;
		  	  $wpdb->query($sql);

       }

       if ($result=='') {
		   // Step 1 - Retrieve current maximum bid on item
		   $table_name = $wpdb->prefix . "wpa_bids";
		   $strSQL = "SELECT * FROM $table_name WHERE auction_id=".$auction_id." ORDER BY current_bid_price DESC LIMIT 1";
		   $current = $wpdb->get_row ($strSQL);
	
		   $result = BID_WIN;
	
		   if (!($current)) {
			  $winner = "new";
	
			  // bid is the starting bid on the auction	
			 $table_name = $wpdb->prefix . "wpa_auctions";
			 $strSQL = "SELECT start_price FROM $table_name WHERE id=".$auction_id;
			 $thisbid = $wpdb->get_var ($strSQL);          
	
		   } else {
			  // let's compare maximum bids first
			  if ($max_bid > $current->max_bid_price) {
				 $winner = "new";
			   
				 // bid is next available one above current bidder's maximum bid
				 $thisbid = $current->max_bid_price + wpa_get_increment($current->max_bid_price);
	
				 // check we haven't exceeded the new bidder's maximum
				 if ($thisbid > ($max_bid + 0)) { $thisbid = $max_bid; }
	
				 //pull in auction details
				 $table_name = $wpdb->prefix . "wpa_auctions";
				 $strSQL = "SELECT id, name,description,current_price,date_create,date_end,start_price,image_url FROM $table_name WHERE id=".$auction_id;
				 $rows = $wpdb->get_row ($strSQL);
	
         if ( $emailoptions['windowsmail'] == "" ) {
				   $headers = "From: " . get_option('blogname') . " <" . get_option('admin_email') . ">\r\n";  
				 } else {
	   			 $headers = "From: " . get_option('admin_email') . "\r\n";
	   		 }
				 $to      = $current->bidder_email;
				 $subject = "[".$title."] You have been outbid on ".$rows->name;

         if ($emailoptions["outbid"] == "") {
   				  $body   = "You have just been outbid on an auction on {site_name}\n\n";
				    $body  .= "Unfortunately someone else is currently winning {auction_name} after placing a bid for {current_price}. ";
				    $body  .= "You're still in time to win the auction, so click the link below and bid again.";
				    $body 	.= "\n\nLink: {auction_link}";         
				    $body 	.= "\n\n--------------------------------------------\n";
				 } else {
				    $body = $emailoptions["outbid"];
				    
				    // clean up CRLFs
				    $body = str_replace("\r\n", "\n", $body);
				 }				
         // prepare link
         if (strlen($staticpage) > 0) {
           $link 	= $staticpage."?auction_id=".$auction_id;         
         } else {
           $link 	= get_option('siteurl')."?auction_to_show=".$auction_id;
         } 
    
         // replace keywords
         $body = str_replace ( "{site_name}", get_option('blogname') , $body );
         $body = str_replace ( "{auction_name}", $rows->name , $body );
         $body = str_replace ( "{auction_link}", $link , $body );
         $body = str_replace ( "{current_price}", $currencysymbol.number_format($thisbid, 2, '.', ','), $body );
				
				 // Send the email.
				 wp_mail($to, $subject, $body, $headers);
	
			  } else {
				 $winner = "old";
	
	       // stick in an extra record in the bids table to track that a new bid has been superceeded
			  $table_name = $wpdb->prefix . "wpa_bids";
			  $sql = "INSERT INTO ".$table_name." (id, auction_id, date, bidder_name ,bidder_email, bidder_url, current_bid_price, max_bid_price, bid_type) VALUES (NULL, ".$auction_id.", '".current_time('mysql',"1")."', '".$bidder_name."', '".$bidder_email."', '".$bidder_url."', ".$max_bid.", ".$max_bid.", 'outbid');";
			  $wpdb->query($sql);
	       
				 // increase bid to take it above new bid
				 $thisbid = $max_bid + wpa_get_increment($max_bid);
	
				 // check we haven't exceeded the old bidder's maximum
				 if ($thisbid > ($current->max_bid_price + 0)) { $thisbid = $current->max_bid_price; }
	
				 // if the old bidder wins, update the write variables with old bidder's details
				$bidder_name = $current->bidder_name;
				$bidder_email = $current->bidder_email;
				$bidder_url = $current->bidder_url;
				$max_bid = $current->max_bid_price;
        $bid_type = "auto";
	
				$result = BID_LOSE;
			  }
		   
           }
       }

		   if ($result == BID_WIN || $result == BID_LOSE || $result == BIN_WIN) {
			  // Update bid table with details on bid
			  $table_name = $wpdb->prefix . "wpa_bids";
			  $sql = "INSERT INTO ".$table_name." (id, auction_id, date, bidder_name ,bidder_email, bidder_url, current_bid_price, max_bid_price, bid_type) VALUES (NULL, ".$auction_id.", '".current_time('mysql',"1")."', '".$bidder_name."', '".$bidder_email."', '".$bidder_url."', ".$thisbid.", ".$max_bid.", '".$bid_type."');";
			  $wpdb->query($sql);
	
			  //Update auction table
			  $table_name = $wpdb->prefix . "wpa_auctions";
			  $sql = "UPDATE ".$table_name." SET current_price = ".$thisbid." WHERE id=".$auction_id;
			  $wpdb->query($sql);

         // notify site owner if notification requested
         if ($notify != '') {
            if ( $emailoptions['windowsmail'] == "" ) {
				       $headers = "From: " . get_option('blogname') . " <" . get_option('admin_email') . ">\r\n";  
				    } else {
	   		      $headers = "From: " . get_option('admin_email') . "\r\n";
	   	     }

				    $to      = $notify;
				    $subject = "[".$title."] New bid on ".$auction_id;
				    $body   = "New bid on your auction.";

            if (strlen($staticpage) > 0) {
				       $body 	.= "\n\nLink: " . $staticpage."?auction_id=".$auction_id;         
            } else {
   				    $body 	.= "\n\nLink: " . get_option('siteurl')."?auction_to_show=".$auction_id;
				    }

				    $body 	.= "\n\n--------------------------------------------\n";
				
				    // Send the email.
				    wp_mail($to, $subject, $body, $headers);
         }
		   }
        
    }
		   
		// finalise auction if BIN
		if ($result == BIN_WIN)  {
       // wait a bit, to make sure Now() in termination check doesn't match NOW() here.
       sleep (2);

		   check_auction_end($auction_id); }

   return $result;
}


function wp_auctions_uninstall () {

   // Cleanup routine. - Deactivated cleanup after to many complaints

   global $wpdb;

//   $table_name = $wpdb->prefix . "wpa_auctions";
//   $wpdb->query("DROP TABLE {$table_name}");

//   $table_name = $wpdb->prefix . "wpa_bids";
//   $wpdb->query("DROP TABLE {$table_name}");   

   wp_clear_scheduled_hook('wpa_daily_check');

}



function wp_auctions_install () {
   global $wpdb;

   $wpa_db_version = "1.5";
   
   $installed_ver = get_option("wpa_db_version");
      
   if ($installed_ver != $wpa_db_version) {
      require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

      $table_name = $wpdb->prefix . "wpa_auctions";
     
      // Create Auctions Table
      
      $sql = "CREATE TABLE " . $table_name . " (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
	  date_create datetime NOT NULL,
	  date_end datetime NOT NULL,
	  name tinytext NOT NULL,
	  description text NOT NULL,
	  image_url tinytext,
	  start_price decimal(10,2) NOT NULL,
	  reserve_price decimal(10,2),
	  current_price decimal(10,2),
	  shipping_price decimal(10,2),
    shipping_to tinytext,
    shipping_from tinytext,
	  duration tinyint,
	  BIN_price decimal(10,2),
    winner tinytext,
    winning_price decimal(10,2),
    extraimage1 tinytext,
    extraimage2 tinytext,
    extraimage3 tinytext,
    staticpage tinytext,
    paymentmethod tinytext,
	  UNIQUE KEY id (id)
	);";

      dbDelta($sql);
     
      // Create Bids Table
   
	  $table_name = $wpdb->prefix . "wpa_bids";   
      
      $sql = "CREATE TABLE " . $table_name . " (
	  id mediumint(9) NOT NULL AUTO_INCREMENT,
	  auction_id mediumint NOT NULL,
	  date datetime NOT NULL,
	  bidder_name tinytext,
	  bidder_email tinytext,
	  bidder_url tinytext,
	  bidder_IP tinytext,
	  current_bid_price decimal(10,2) NOT NULL,
	  max_bid_price decimal(10,2),
	  bid_type tinytext,
	  UNIQUE KEY id (id)
	);";

      dbDelta($sql);
  
      update_option("wpa_db_version", $wpa_db_version);
      
      //set initial values if none exist
      $options = get_option('wp_auctions');
      if ( !is_array($options) ) {
         $options = array( 'title'=>'WP Auctions', 'currency'=>'2', 'style'=>'default', 'notify'=>'', 'paypal'=>'', 'currencysymbol'=>'$', 'currencycode'=>'USD');
         update_option('wp_auctions', $options);
      }
       
   }
   
   wp_schedule_event(time(), 'twicedaily', 'wpa_daily_check');
}

function close_expired_auctions() {
	// scheduled event to ensure auctions close properly
	
  global $wpdb;
 $table_name = $wpdb->prefix . "wpa_auctions";
 $strSQL = "SELECT id FROM $table_name WHERE winner IS NULL";
 $rows = $wpdb->get_results ($strSQL);
 
 foreach ($rows as $row) { 
    check_auction_end ($row->id);
 }
}


function check_auction_end($auction_id) {

   global $wpdb;

   // make sure we have a numeric auction number
   $auction_id = $auction_id + 0;

   $options = get_option('wp_auctions');
   $paypal = $options['paypal'];
   $mailingaddress = $options['mailingaddress'];
   $bankdetails = $options['bankdetails'];
   $currencysymbol = $options['currencysymbol'];
   $currencycode = $options['currencycode'];
   $notify = $options['notify'];
   $title = $options['title'];

   // Setup email fields.         
   $emailoptions = get_option('wp_auctions_email');
   
   // prepare result
   $table_name = $wpdb->prefix . "wpa_auctions";
   $strSQL = "SELECT id, '".current_time('mysql',"1")."' <= date_end, winner, shipping_price, paymentmethod FROM $table_name WHERE id=".$auction_id;
   $rows = $wpdb->get_row ($strSQL, ARRAY_N);

   // pull out shipping/payment details
   $shipping_price = $rows[3];
   $payment_method = $rows[4];

   if ($rows[0] == $auction_id && $rows[1] == 0 && $rows[2] == '') {
      // auction has closed - update winner and price

      // prepare result
      $table_name = $wpdb->prefix . "wpa_bids";
	    $strSQL = "SELECT bidder_name, bidder_email, date, current_bid_price FROM $table_name WHERE auction_id=".$auction_id." ORDER BY current_bid_price DESC, bid_type LIMIT 1";
	    $bidrows = $wpdb->get_row ($strSQL);

      if ($bidrows != '') {  // there is a bid
         //update database
         $table_name = $wpdb->prefix . "wpa_auctions";
         $strSQL = "UPDATE $table_name SET winner='$bidrows->bidder_name', winning_price = '$bidrows->current_bid_price' WHERE id=" . $auction_id;
         $wpdb->query($strSQL);
      
         // get details for mail
         $strSQL = "SELECT * FROM $table_name WHERE id=".$auction_id;
         $rows = $wpdb->get_row ($strSQL);

         $emailoptions = get_option('wp_auctions_email');

         if ( $emailoptions['windowsmail'] == "" ) {
				   $headers = "From: " . get_option('blogname') . " <" . get_option('admin_email') . ">\r\n";  
				 } else {
	   			 $headers = "From: " . get_option('admin_email') . "\r\n";
	   		 }
				 $to      = $bidrows->bidder_email;
	       $subject = "[".$title."] Auction Closed: ".$rows->name;

         if ($emailoptions["win"] == "") {
   	        $body   = "Congratulations! You have just won the following auction on {site_name}.";
	          $body 	.= "\n\nAuction: {auction_name} for {current_price}";
				    $body 	.= "\n\nLink: {auction_link}";         
				    $body 	.= "\n\n--------------------------------------------\n";
				    $body 	.= "{payment_details}";
            $body 	.= "\n\nShould you require any further assistance, please contact me at {contact_email}.";
	          $body 	.= "\n\n--------------------------------------------\n";
	          
				 } else {
				    $body = $emailoptions["win"];

				    // clean up CRLFs
				    $body = str_replace("\r\n", "\n", $body);
				 }				
         // prepare link
         if (strlen($rows->staticpage) > 0) {
           $link 	= $rows->staticpage."?auction_id=".$auction_id;         
         } else {
           $link 	= get_option('siteurl')."?auction_to_show=".$auction_id;
         } 

         // prepare payment
 	       switch ($payment_method) {
	          case "paypal":
     	         $payment  = "\n\nYou can pay for the auction by clicking on the link below:";
	             $payment .= "\n\nhttps://www.paypal.com/cgi-bin/webscr?cmd=_xclick&business=".urlencode($paypal)."&item_name=".urlencode($rows->name)."&amount=".urlencode($rows->winning_price)."&shipping=".urlencode($shipping_price)."&no_shipping=0&no_note=1&currency_code=".$currencycode."&lc=GB&bn=PP%2dBuyNowBF&charset=UTF%2d8";
	             break;
	          case "bankdetails":
     	         $payment	 = "\n\nMy banking details are as follows:\n\n";
     	         $payment .= $bankdetails;
	             $payment .= "\n\nPlease submit your payment for ".$currencysymbol.($rows->winning_price+$shipping_price)." using the auction number (".$auction_id.") as a reference";
	             break;
	          case "mailingaddress":
     	         $payment  = "\n\nMy postal address is as follows:\n\n";
     	         $payment .= $mailingaddress;
	             $payment	.= "\n\nPlease send me a cheque or postal order for ".$currencysymbol.($rows->winning_price+$shipping_price)." quoting the auction number (".$auction_id.") as a reference";
	             break;	       
	       }
	          
         // replace keywords
         $body = str_replace ( "{site_name}", get_option('blogname') , $body );
         $body = str_replace ( "{auction_name}", $rows->name , $body );
         $body = str_replace ( "{auction_link}", $link , $body );
         $body = str_replace ( "{payment_details}", $payment , $body );
         $body = str_replace ( "{current_price}", $currencysymbol . $rows->winning_price . "( " . $currencysymbol . $shipping_price . " shipping)", $body );
         $body = str_replace ( "{contact_email}", get_option('admin_email') , $body );
			
				 // Send the email.
	       wp_mail($to, $subject, $body, $headers);
     

      // notify site owner if notification requested
	  if ($notify != '') {
		 // Setup email fields.
     if ( $emailoptions['windowsmail'] == "" ) {
       $headers = "From: " . get_option('blogname') . " <" . get_option('admin_email') . ">\r\n";  
     } else {
       $headers = "From: " . get_option('admin_email') . "\r\n";
     }
		 $to      = $notify;
		 $subject = "[".$title."] Auction Closed: ".$auction_id;
		 $body   = "Your auction has closed.";

     if (strlen($rows->staticpage) > 0) {
				$body 	.= "\n\nLink: " . $rows->staticpage."?auction_id=".$auction_id;         
     } else {
			 $body 	.= "\n\nLink: " . get_option('siteurl')."?auction_to_show=".$auction_id;
		}
	       switch ($payment_method) {
	          case "paypal":
     	         $body 	.= "\n\nThe winner has been sent an email with a PayPal link to complete the transaction";
	             break;
	          case "bankdetails":
     	         $body 	.= "\n\nThe winner has been sent an email with your bank details and will be remitting payment shortly (reference: ".$auction_id.")";
	             break;
	          case "mailingaddress":
     	         $body 	.= "\n\nThe winner has been sent an email with your mailing address and requested to quote reference: ".$auction_id;
	             break;	       
	       }
		 $body 	.= "\n\n--------------------------------------------\n";
		
		 // Send the email.
		 wp_mail($to, $subject, $body, $headers);
      }   
   }
   }
}

function widget_wp_auctions_init() {


	function widget_wp_auctions($args) {

		extract($args);

		echo $before_widget;
		docommon_wp_auctions();
		echo $after_widget;
	}
	
	function widget_wp_auctions_control() {
				
		echo '<p>';
		echo _e('Please configure the widget from the Auctions Configuration Screen','wpauctions');
		echo '</p>';
	}
	
	wp_register_sidebar_widget('wp_auctions','WP Auctions', 'widget_wp_auctions', null, 'WP Auctions');
	wp_register_widget_control('wp_auctions','WP Auctions', 'widget_wp_auctions_control', null, 75, 'akismet');

//	register_sidebar_widget(array('WP Auctions', 'widgets'), 'widget_wp_auctions');
//	register_widget_control(array('WP Auctions', 'widgets'), 'widget_wp_auctions_control', 300, 130);
;
}

function get_price($current_price,$start_price,$BIN_price,$currencysymbol,$sep) {

   $printstring = "undefined";
   if (($start_price<0.01) && ($BIN_price>0.01)) {
      $printstring = __('Buy It Now','wpauctions').$sep.$currencysymbol.number_format($BIN_price, 2, '.', ',');
   } else {   
       if ($current_price>0.01) { // then show the current price
          $printstring = __('Going for','wpauctions').$sep.$currencysymbol.number_format($current_price, 2, '.', ',');      
       } else { // then show the start price
             $printstring = __('Starting at','wpauctions').$sep.$currencysymbol.number_format($start_price, 2, '.', ',');
       }
   }
   return $printstring;
}

function wp_auctions(){

   docommon_wp_auctions();
}

// Post Auction goes here
function dopost_wp_auctions($auction_id) {

   global $wpdb;

   $options = get_option('wp_auctions');
   $style = $options['style'];
   $currencysymbol = $options['currencysymbol'];
   $title = $options['title'];
   $regonly = $options['regonly'];
   $currencycode = $options['currencycode'];
   $customcontact = $options['customcontact'];
    
   $options = get_option('wp_auctions');

   if ($_GET['auction_id'] > 0) {
      $auction_id = $_GET['auction_id'];
   }
	
   
   // First of all, has a bid just been posted?
   $result = "";
   if ( $_POST["mode"] == "bid" ) { 
   
     $auction_id = $_POST['auction_id'];
	   $bidder_name = htmlspecialchars(strip_tags(stripslashes($_POST['bidder_name'])), ENT_QUOTES);
	   $bidder_email = strip_tags(stripslashes($_POST['bidder_email']));
	   $bidder_url = htmlspecialchars(strip_tags(stripslashes($_POST['bidder_url'])), ENT_QUOTES);
	   $max_bid = $_POST['max_bid'];
	   $BIN_amount = $_POST['BIN_Amount'];
	   
     $result = wpa_process_bid( $auction_id, $bidder_name, $bidder_email, $bidder_url, $max_bid, $BIN_amount );
   }
   
   // do some pre-work on whether we need registration or not and what the default settings are
	 $needreg = false;
	 if (($regonly=="Yes") && !is_user_logged_in()) {  
	    $needreg = true;
	 } else { 
	  
	  // if the user is logged in .. might as well prepopulate the form
	  $defaultname = "";
	  $defaultemail = "";
	  $defaulturl = "";
	  if (is_user_logged_in()) {
	     global $current_user;
	     get_currentuserinfo();
	     
	     $defaultname = $current_user->display_name;
	     $defaultemail = $current_user->user_email;
	     $defaulturl = $current_user->user_url;
	  }
   }	  
	  
   // select the correct record
   $table_name = $wpdb->prefix . "wpa_auctions";

   // don't have an ID? let's get a random one
   if(!is_numeric($auction_id)) {    
      // let's see if we can work out which auction we need from the database
      $strSQL = "SELECT id FROM ".$table_name." WHERE staticpage='".get_permalink()."'";
      echo "<!-- $strSQL -->";
      
      $row = $wpdb->get_row ($strSQL);
      $auction_id = $row->id;
      
      //echo "<!-- Going with $auction_id -->";
      
   } //else {  echo "<!-- Going with $auction_id -->"; }
      
   // if we *still* don't have an ID .. let's just pick a random one     
   if(!is_numeric($auction_id)) {  
      $cond = "'".current_time('mysql',"1")."' < date_end order by rand() limit 1";
   } else {
      $cond = "id=".$auction_id;
   }
   $strSQL = "SELECT id, image_url, extraimage1, extraimage2, extraimage3, name, description, date_end, duration, BIN_price, start_price, current_price, shipping_price, shipping_to, shipping_from, paymentmethod, staticpage, '".current_time('mysql',"1")."' < date_end AS active FROM ".$table_name." WHERE ".$cond;
   $row = $wpdb->get_row ($strSQL);

   // grab values we need
   $image_url = $row->image_url;
   $name = $row->name;
   $description = $row->description;
   $end_date = get_date_from_gmt($row->date_end);
   $current_price = $row->current_price;
   $BIN_price = $row->BIN_price;
   $start_price = $row->start_price;
   $id = $row->id;
   $shipping_price = $row->shipping_price;
   $shipping_to = $row->shipping_to;
   $shipping_from = $row->shipping_from;
   $staticpage = $row->staticpage;
   $active = $row->active;
   $payment_method = $row->paymentmethod;
   $extraimage = array($row->extraimage1, $row->extraimage2, $row->extraimage3 );
   
   // work out next min bid
   if ($start_price > $current_price) {
	   $nextbid = $start_price;
   } else {
       $nextbid = $currencysymbol . number_format($current_price + wpa_get_increment($current_price), 2, '.', ',');
   }

	// get bids
	$table_name = $wpdb->prefix . "wpa_bids";
	$strSQL = "SELECT bidder_name, bidder_url ,date,current_bid_price, bid_type FROM $table_name WHERE auction_id=".$auction_id." ORDER BY current_bid_price DESC, bid_type";
	$rows = $wpdb->get_results ($strSQL);

  $printstring = '<!-- Wp Code Starts Here-->';

  $printstring .= '<SCRIPT language="JavaScript">function clickBid() {  document.auctionform.submit(); }</SCRIPT>';

  if ( $BIN_price > 0 ) {
     $printstring .= '<SCRIPT language="JavaScript">function clickBuy() {  document.auctionform.max_bid.value = '.$BIN_price.'; document.auctionform.BIN_Amount.value = '.$BIN_price.'; document.auctionform.submit(); }</SCRIPT>';
  }

  $printstring .= '<div class="wpauction" id="wpauction">';

	$printstring .= '<h3>'.$name.'</h3>';
	
	$printstring .= '<div class="auctionimages">';
	$printstring .= '<a href="'.wp_get_attachment_url($image_url).'" title="'.$name.'" class="thickbox"><img src="'.wpa_resize($image_url,125).'" alt="Auction Image" width="100" /></a>';

	for ($i = 0; $i <= 2; $i++) {
     if ($extraimage[$i] != "" ) {
   	   $printstring .= '<a href="'.wp_get_attachment_url($extraimage[$i]).'" title="'.$name.'" class="thickbox"><img src="'.wpa_resize($extraimage[$i],125).'" alt="Auction Image" width="100" /></a>';  
     } 
  }
	$printstring .= '</div>';
	
	
	$printstring .= '<div class="auctiondescription">';
	$printstring .= wpautop($description);
	$printstring .= '</div>';

   if ($result != "") {
      
      $colour = "red";
      if ($result == BID_WIN || $result == BIN_WIN) { $colour = "green"; }
      
      $printstring .= '<div id="auction-alert" style="background:'.$colour.'; padding: 5px; text-align: center; color: #fff;">'.$result.'</div>';   
   }
   
   $printstring .= '<div class="auctiondetails">';
	
	$printstring .= '<p title="'.get_price($current_price,$start_price,$BIN_price,$currencysymbol," ").', place your bid now!" class="current-bid">'.get_price($current_price,$start_price,$BIN_price,$currencysymbol," ").'</p>';
	$printstring .= '<p class="refresh"><a href="'.get_permalink().'?auction_id='.$auction_id.'" title="'.__('Refresh the current bid','wpauctions').'">'.__('Refresh Current Bid','wpauctions').'</a></p>';
	
	$printstring .= '<ul>';
	$printstring .= '<li title="'.__('Auction dends on this date','wpauctions').'">'.__('Ending Date','wpauctions').' - '. date('dS M Y H:i:s',strtotime($end_date)) .'</li>';
	
	if ($shipping_price > 0) {
	   $printstring .= '<li title="'.__('Shipping price will be added to total','wpauctions').'">'.__('Shipping','wpauctions').' - '.$currencysymbol.$shipping_price.'</li>';  }
	if ($shipping_to != '') {
	   $printstring .= '<li title="'.__('Seller ships to designated locations','wpauctions').'">'.__('Ships to','wpauctions').' - '.$shipping_to.'</li>'; }
	if ($shipping_from != '') {	   
	   $printstring .= '<li title="'.__('Item will be shipped from this location','wpauctions').'">'; 
	   $printstring .= '<address>';
	   $printstring .= '<span>'.__('Location','wpauctions').'</span> - '.$shipping_from;
	   $printstring .= '</address>';
	   $printstring .= '</li>'; }
	$printstring .= '</ul>';
	
	$printstring .= '</div>';

	$printstring .= '<div class="auctiontables">';

  if ($active) {
	$printstring .= '<h6>'.__('Place Your Bid Here','wpauctions').'</h6><span>'.__('Bid','wpauctions').' '.$nextbid.' '.__('or higher','wpauctions').' [<a href="http://www.wpauctions.com/faq/" target="_blank" rel="nofollow">?</a>]</span>';

    $printstring .= '<form action="'.$staticpage.'#auction-alert" method="POST" name="auctionform">';
    $printstring .= '<table width="100%" cellpadding="0" cellspacing="0">';
    
    if ($needreg) {
       $printstring .= '<tr>';
       $printstring .= '<td colspan="2">'.__('Bidding allowed for registered users only','wpauctions').'. <a href="'.wp_login_url( $_SERVER['REQUEST_URI'] ).'">'.__('Register or Log in','wpauctions').'</a></td>';
	   
       $printstring .= '</tr>';

    } else {
        $printstring .= '<tr>';
        $printstring .= '<td width="120">'.__('Name','wpauctions').'</td>';

        $printstring .= '<td><input name="bidder_name" type="text" class="bid-input" tabindex="1" value="'.$defaultname.'" /> *</td>';
        $printstring .= '</tr>';
        $printstring .= '<tr>';
        $printstring .= '<td width="120">'.__('Email','wpauctions').'</td>';
        $printstring .= '<td><input name="bidder_email" type="text" class="bid-input" tabindex="2" value="'.$defaultemail.'" /> *</td>';
        $printstring .= '</tr>';

        $printstring .= '<tr>';
        if ($customcontact == "") {
           $printstring .= '<td width="120">'.__('Web URL','wpauctions').'</td>';
        } else {
           $printstring .= '<td width="120">'.$customcontact.'</td>';        
        }
        $printstring .= '<td><input name="bidder_url" type="text" class="bid-input" tabindex="3" value="'.$defaulturl.'" /></td>';
        $printstring .= '</tr>';
        
        // cater for Immediate
        if ($start_price > 0) {
           $printstring .= '<tr>';
           $printstring .= '<td width="120">'.__('Bid Amount','wpauctions').'</td>';
           $printstring .= '<td><input name="max_bid" type="text" class="bid-input" tabindex="4" /> * '.$currencycode.'</td>';

           $printstring .= '</tr>';

           $printstring .= '<tr>';
           $printstring .= '<td width="120"><div id="BIN"></div>&nbsp;</td>';

           $printstring .= '<td><input name="'.__('Bid Now','wpauctions').'" type="button" value="'.__('Bid Now','wpauctions').'" class="auction-button" title="Bid Now" tabindex="5" onClick="clickBid()"/></td>';
           $printstring .= '</tr>';

        } else {
          $printstring .= '<input type="hidden" name="max_bid" value="'.$BIN_price.'">';	  
        }

    }
    $printstring .= '</table>';
	
    $printstring .= '<input type="hidden" name="mode" value="bid">';
    $printstring .= '<input type="hidden" name="auction_id" value="'.$auction_id.'">';	
    $printstring .= '<input type="hidden" name="BIN_Amount" value="">';	
    $printstring .= '</form>';


    if ( $BIN_price > 0 ) {
       if (!$needreg) {
		  $printstring .= '<h6>'.__('Buy it Now','wpauctions').'</h6>';
          $printstring .= '<table width="100%" cellpadding="0" cellspacing="0">';
          $printstring .= '<tr>';
          $printstring .= '<td width="120">'.__('Buy it Now Price','wpauctions').'</td>';
          $printstring .= '<td><strong>'.$currencysymbol.number_format($BIN_price, 2, '.', ',').'</strong></td>';
          $printstring .= '</tr>';
          $printstring .= '<tr>';
          $printstring .= '<td width="120">'.__('Click to Buy','wpauctions').'</td>';
          $printstring .= '<td><input name="'.__('Buy Now','wpauctions').'" type="button" value="'.__('Buy Now','wpauctions').'" class="auction-button" title="Buy it Now" onClick="clickBuy()"/></td>';
          $printstring .= '</tr>';

          $printstring .= '</table>';
       }
    }
  } else {
    $printstring .= '<p style="text-align: center;">'.__('Auction closed','wpauctions').'</p>';
    
  }
    
	$printstring .= '</div>';
		
	$printstring .= '<div class="auctiondetails">';
	$printstring .= '<h6>'.__('Current bids','wpauctions').'</h6>';
	$printstring .= '<ol>';
	foreach ($rows as $bid) {
		$printstring .= '<li>';
	   if ($bid->bidder_url != "" && $customcontact = "") {
	      $printstring .= '<a href="'.$bid->bidder_url.'" rel="nofollow">'.$bid->bidder_name.'</a>';
	   } else {
	      $printstring .= $bid->bidder_name;
	   }
	   $printstring .= ' bid '.$currencysymbol.number_format($bid->current_bid_price, 2, '.', ',').' on '.get_date_from_gmt($bid->date);
	   if ($bid->bid_type == "auto") $printstring .= ' [auto]';
	   $printstring .= '</li>';
   }
$printstring .= '</ol>';
	$printstring .= '</div>';
	
	// part moved ends here
	
	$printstring .= '<div class="auctiontables">';
	$printstring .= '<h6>'.__('Payment Details','wpauctions').'</h6>';

	$printstring .= '<p>'.__('Payment must be made using the following method','wpauctions').'</p>';
	$printstring .= '<table width="100%" border="0" cellpadding="0" cellspacing="0">';


   switch ($payment_method) {
      case "paypal":
         $printstring .= '		  <tr>';
         $printstring .= '			<td>PayPal</td>';
         $printstring .= '			<td>'.__('Auction winner will get a PayPal payment link via email.','wpauctions').'</td>';
         $printstring .= '		  </tr>';
         break;
      case "bankdetails":
         $printstring .= '		  <tr>';
         $printstring .= '			<td>'.__('Wire Transfer','wpauctions').'</td>';
         $printstring .= '			<td>'.__('Bank details will be provided to the auction winner via email.','wpauctions').'</td>';
         $printstring .= '		  </tr>';
         break;
      case "mailingaddress":
         $printstring .= '		  <tr>';
         $printstring .= '			<td>'.__('Cheque or postal order','wpauctions').'</td>';
         $printstring .= '			<td>'.__('Address will be provided to the auction winner.','wpauctions').'</td>';
         $printstring .= '		  </tr>';
         break;	       
   }

	$printstring .= '  	  </table>';
	$printstring .= '	</div>';

   $printstring .= '</div>';
      
   $printstring .= '<!-- Code Ends Here -->';
 
  return $printstring;
}

// Sidebar code goes here
function docommon_wp_auctions() {

   global $wpdb;

   $options = get_option('wp_auctions');
   $style = $options['style'];
   $currencysymbol = $options['currencysymbol'];
   $title = $options['title'];
   $list = $options['list'];
   $feedback = $options['feedback'];
   $noauction = $options['noauction'];
   $otherauctions = $options['otherauctions'];
   $showrss = $options['showrss'];
   
   $chunks = explode('<!--more-->', $noauction);
   $chunkno = mt_rand(0, sizeof($chunks) - 1);
   $noauctiontext = $chunks[$chunkno];

   // select a random record
   $table_name = $wpdb->prefix . "wpa_auctions";

   $auction_id = $_GET["auction_to_show"];

   if(!is_numeric($auction_id)) {
      $cond = "'".current_time('mysql',"1")."' < date_end order by rand() limit 1";
   } else {
      $cond = "id=".$auction_id;
   }
   $strSQL = "SELECT id, image_url, name, description, date_end, duration, BIN_price, start_price, current_price, staticpage FROM ".$table_name." WHERE ".$cond;
   $row = $wpdb->get_row ($strSQL);

   // grab values we need
   $image_url = $row->image_url;
   $name = $row->name;
   $description = substr($row->description,0,75)."...";
   $end_date = get_date_from_gmt($row->date_end);
   $current_price = $row->current_price;
   $BIN_price = $row->BIN_price;
   $start_price = $row->start_price;
   $id = $row->id;
   $staticpage = $row->staticpage;

if ($list == "Yes") {

   echo '<!-- Main WP Container Starts -->';

   // cater for no records returned
   if ($id == '') {
      echo '<div id="wp-container">';
      echo '<div style="border: 1px solid #ccc; padding: 5px 2px; margin: 0px !important; background: none !important;">';
      echo ( $noauctiontext ); 
      echo '</div>';
      echo '</div>';
   } else {
      echo '<div id="wp-container">';
      echo '<div class="wp-head-list">'.$title.'</div>';
      echo '<div class="wp-body-list">';
      
      // selected auction first
	  echo '<div class="wp-auction-hold">';
      echo '<img src="'.wpa_resize($image_url,50).'" height="50" width="50" align="left" style="margin-right: 5px;" />';
      echo '<div class="wp-heading-list">'.$name.'</div>';
      if (strlen($staticpage) > 0) {
         echo '<div class="wp-desc-list">'.$description.'<span class="wp-more"> - <a href="'.$staticpage.'?auction_id='.$id.'" title="read more">'.__('more...','wpauctions').'</a></span></div>';
         echo '<div class="wp-bidnow-list"><a href="'.$staticpage.'?auction_id='.$id.'" title="read more">'.get_price($current_price,$start_price,$BIN_price,$currencysymbol," - ").'</a></div>';
      } else {
         echo '<div class="wp-desc-list">'.$description.'<span class="wp-more"> - <a href="'.WPA_PLUGIN_URL . '/auction.php?ID=' . $id . POPUP_SIZE.'"  class="thickbox" title="read more">'.__('more...','wpauctions').'</a></span></div>';
		 echo '</div>';
         echo '<div class="wp-bidnow-list"><a href="'.WPA_PLUGIN_URL . '/auction.php?ID=' . $id.POPUP_SIZE. '"  class="thickbox" title="read more">'.get_price($current_price,$start_price,$BIN_price,$currencysymbol," - ").'</a></div>';
      }         

      // select "other" auctions
      $table_name = $wpdb->prefix . "wpa_auctions";

      $strSQL = "SELECT * FROM ".$table_name." WHERE '".current_time('mysql',"1")."' < date_end and id<>".$id." order by rand()";  // show all other auctions
      $rows = $wpdb->get_results ($strSQL);

      foreach ($rows as $row) {  

         $image_url = $row->image_url;

		    echo '<div class="wp-auction-hold">';
        echo '<img src="'.wpa_resize($image_url,50).'" height="50" width="50" align="left" style="margin-right: 5px;" />';
        echo '<div class="wp-heading-list">'.$row->name.'</div>';
        echo '<div class="wp-desc-list">'.substr($row->description,0,75)."...".'<span class="wp-more"> - ';

        if (strlen($row->staticpage) > 0) {
           $link = '<a href="'.$row->staticpage.'?auction_id='.$row->id.'" title="read more">';
        } else {
           $link = '<a href="'.WPA_PLUGIN_URL . '/auction.php?ID=' . $row->id.POPUP_SIZE. '" class="thickbox" title="read more">';        
        }
        
        echo $link;
        echo ''.__('more...','wpauctions').'</a></span></div>';
    		echo '</div>';
        echo '<div class="wp-bidnow-list">'.$link.get_price($row->current_price,$row->start_price,$row->BIN_price,$currencysymbol," - ").'</a></div>';

      }       
      if ($showrss != "No") {
         echo '<div class="wp-rss"><a href="'.WPA_PLUGIN_RSS .'"><img src="'.WPA_PLUGIN_REQUISITES.'/rss.png" alt="Auctions RSS Feed" border="0" title="Grab My Auctions RSS Feed"/>'.__('Auctions RSS Feed','wpauctions').'</a></div>';
      }

      echo '</div>';
      echo '</div>'; 
   }
   echo '<!-- Main WP Container Ends -->';

} else {

   // cater for no records returned
   if ($id == '') {
?>

<!--WP-Auction - Sidebar Presentation Section -->     
<div id="wp-container">
   
  <?php if ($noauctiontext != '') { ?>
  <div style="border: 1px solid #ccc; padding: 5px 2px; margin: 0px !important; background: none !important;">
      <?php echo $noauctiontext ?>
  </div>

  <?php } else { //noauctiontext is blank ?>
    <div id="wp-head"><?php echo $title ?></div>

    <div id="wp-body">
      <div id="wp-image"><img src="<?php echo wpa_resize($image_url,125) ?>" width="125" height="125" /></div>
      <div class="wp-heading"><?php _e("No auctions found",'wpauctions'); ?></div>
      <div id="wp-desc"><?php _e("Sorry, we seem to have sold out of everything we had!",'wpauctions'); ?></div>
    <div id="wp-other"></div>
    </div>
    <div id="wp-bidcontainer"></div>
  <!-- Main WP Container Ends -->  
  <?php } ?>
</div>
<!--WP-Auction - End -->     
<?php  
} else {

   // select "other" auctions
   $table_name = $wpdb->prefix . "wpa_auctions";

   $thelimit = "";
   if ($otherauctions != 'all' && $otherauctions > 0) {
      $thelimit = " limit ".$otherauctions;
   }

   $strSQL = "SELECT id, name, staticpage  FROM ".$table_name." WHERE '".current_time('mysql',"1")."' < date_end and id<>".$id." order by rand()".$thelimit;
   $rows = $wpdb->get_results ($strSQL);

   // prepare auction link
   if (strlen($staticpage) > 0) { 
      $auctionlink = '<a href="'.$staticpage.'?auction_id='.$id.'" title="Bid Now">';
   } else {
      $auctionlink = '<a href="'.WPA_PLUGIN_URL . '/auction.php?ID=' . $id .POPUP_SIZE.'" class="thickbox" title="Bid Now">';
   }
?>
<!--WP-Auction - Sidebar Presentation Section -->     
  <!-- Main WP Container Starts -->
  <div id="wp-container">
    <div id="wp-head"><?php echo $title ?></div>

    <div id="wp-body">
      <div id="wp-image"><?php echo $auctionlink; ?><img src="<?php echo wpa_resize($image_url,125) ?>" width="125" height="125" /></a></div>
      <div class="wp-heading"><?php echo $name ?></div>

      <div id="wp-desc"><?php echo $description; ?><span class="wp-more"> - <?php echo $auctionlink; ?><?php _e('more...','wpauctions'); ?></a></span> </div>

      <?php if ($BIN_price > 0): ?>
         <div id="wp-date">B.I.N.: <?php echo $currencysymbol.number_format($BIN_price, 2, '.', ',') ?></div>
      <?php endif ?>
      <div id="wp-date"><?php _e('Ending','wpauctions'); ?>: <?php echo date('dS M Y H:i:s',strtotime($end_date)) ?></div>

      <div id="wp-other">

	<?php if (!empty($rows)): ?>      
        <div class="wp-heading"><?php _e("Other Auctions",'wpauctions'); ?></div>
        <ul>
      <?php foreach ($rows as $row) {  
         echo "<li>";
         if (strlen($row->staticpage) > 0) {
            echo "- <a href='".$row->staticpage."?auction_id=".$row->id."'>";
         } else {
            echo "- <a href='".get_bloginfo('wpurl')."?auction_to_show=".$row->id."'>";
         }
         echo $row->name;
         echo "</a></li>";
      } ?>
        </ul>
   <?php endif; ?>
        <?php if ($showrss != "No") { ?>
           <div class="wp-rss"><a href="<?php echo WPA_PLUGIN_RSS; ?>"><img src="<?php echo WPA_PLUGIN_REQUISITES; ?>/rss.png" alt="Auctions RSS Feed" border="0" title="Grab My Auctions RSS Feed"/></a> <a href="<?php echo WPA_PLUGIN_RSS; ?>" title="Grab My Auctions RSS Feed" >Auctions RSS Feed</a></div>
        <?php } ?>
      </div>
    </div>
    <div id="wp-bidcontainer" class="clearfix">
      <div id="wp-bidcontainerleft"><strong style="font-size: 12px; font-weight: bold;"><?php echo get_price($current_price,$start_price,$BIN_price,$currencysymbol,"<br>") ?></strong></div>

      <div id="wp-bidcontainerright"><strong style="font-size: 16px; padding-top: 5px; display: block; font-weight: bold;"><?php echo $auctionlink; ?><?php _e("Bid Now",'wpauctions'); ?></a></strong></div>

    </div>
    
  </div>
  <!-- Main WP Container Ends -->
<!--WP-Auction - End -->     
<?php

}

// hook to terminate auction if needed (not strictly correct, but more efficient if it's here)
check_auction_end($id);
  
}     

}


function wp_auctions_options() {

   // Note: Options for this plugin include a "Title" setting which is only used by the widget
   $options = get_option('wp_auctions');
	
   //set initial values if none exist
   if ( !is_array($options) ) {
      $options = array( 'title'=>'WP Auctions', 'otherauctions'=>'3', 'currency'=>'1', 'style'=>'default', 'notify'=>'', 'paypal'=>'', 'mailingaddress'=>'', 'bankdetails'=>'', 'currencysymbol'=>'$', 'currencycode'=>'USD','noauction'=>'','customcontact'=>'','customincrement'=>'');
   }

   if ( isset($_POST['wp_auctions-submit'] )) {

      // security check
      check_admin_referer( 'WPA-nonce');

      $options['currency'] = strip_tags(stripslashes($_POST['wpa-currency']));
      $options['title'] = strip_tags(stripslashes($_POST['wpa-title']));
      $options['notify'] = strip_tags(stripslashes($_POST['wpa-notify']));
      $options['paypal'] = strip_tags(stripslashes($_POST['wpa-paypal']));
      $options['mailingaddress'] = strip_tags(stripslashes($_POST['wpa-mailingaddress']));
      $options['bankdetails'] = strip_tags(stripslashes($_POST['wpa-bankdetails']));
      $options['list'] = strip_tags(stripslashes($_POST['wpa-list']));
      $options['feedback'] = strip_tags(stripslashes($_POST['wpa-feedback']));
      $options['regonly'] = strip_tags(stripslashes($_POST['wpa-regonly']));
      $options['otherauctions'] = strip_tags(stripslashes($_POST['wpa-otherauctions']));
      $options['customcontact'] = strip_tags(stripslashes($_POST['wpa-customcontact']));
      $options['noauction'] = stripslashes($_POST['wpa-noauction']); // don't strip tags
      $options['style'] = strip_tags(stripslashes($_POST['wpa-style']));
      $options['customincrement'] = strip_tags(stripslashes($_POST['wpa-customincrement']));
      $options['showrss'] = strip_tags(stripslashes($_POST['wpa-showrss']));

      // make sure we clear custom increment if drop down is set to standard
      if (strip_tags(stripslashes($_POST['wpa-bidincrement'])) == "1") {
         $options['customincrement'] = "";
      }
      
      // Currencies handled here
      if ($options['currency']==1) {
         $options['currencysymbol']="&pound;";
         $options['currencycode']="GBP";
      }

      if ($options['currency']==2) {
         $options['currencysymbol']="$";
         $options['currencycode']="USD";
      }
      
      if ($options['currency']==3) {
         $options['currencysymbol']="&#128;";
         $options['currencycode']="EUR";
      }

      if ($options['currency']==4) {
         $options['currencysymbol']="&yen;";
         $options['currencycode']="JPY";
      }

      if ($options['currency']==5) {
         $options['currencysymbol']="A$";
         $options['currencycode']="AUD";
      }

      if ($options['currency']==6) {
         $options['currencysymbol']="C$";
         $options['currencycode']="CAD";
      }

      if ($options['currency']==7) {
         $options['currencysymbol']="NZ$";
         $options['currencycode']="NZD";
      }

      if ($options['currency']==8) {
         $options['currencysymbol']="Fr";
         $options['currencycode']="CHF";
      }

      if ($options['currency']==9) {
         $options['currencysymbol']="S$";
         $options['currencycode']="SGD";
      }

      if ($options['currency']==99) {
         $options['currencysymbol']=strip_tags(stripslashes($_POST['wpa-currencysymbol']));;
         $options['currencycode']=strip_tags(stripslashes($_POST['wpa-currencycode']));;
      }

      update_option('wp_auctions', $options);
   }

   $currencysymbol = htmlspecialchars($options['currencysymbol'], ENT_QUOTES);
   $currencycode = htmlspecialchars($options['currencycode'], ENT_QUOTES);

   $currency = htmlspecialchars($options['currency'], ENT_QUOTES);
   $title = htmlspecialchars($options['title'], ENT_QUOTES);
   $notify = htmlspecialchars($options['notify'], ENT_QUOTES);
   $paypal = htmlspecialchars($options['paypal'], ENT_QUOTES);
   $mailingaddress = htmlspecialchars($options['mailingaddress'], ENT_QUOTES);
   $bankdetails = htmlspecialchars($options['bankdetails'], ENT_QUOTES);
   $list = htmlspecialchars($options['list'], ENT_QUOTES);
   $feedback = htmlspecialchars($options['feedback'], ENT_QUOTES);
   $noauction = htmlspecialchars($options['noauction'], ENT_QUOTES);
   $regonly = htmlspecialchars($options['regonly'], ENT_QUOTES);
   $otherauctions = htmlspecialchars($options['otherauctions'], ENT_QUOTES);
   $customcontact = htmlspecialchars($options['customcontact'], ENT_QUOTES);
   $style = htmlspecialchars($options['style'], ENT_QUOTES);
   $customincrement = htmlspecialchars($options['customincrement'], ENT_QUOTES);
   $showrss = htmlspecialchars($options['showrss'], ENT_QUOTES);

  // Prepare style list based on styles in style folder
	$folder_array=array();
	$folder_count = 1;

	//$path=ABSPATH.WPA_PLUGIN_URL.'/styles/';
	$path = ABSPATH.'wp-content/plugins/'.WPA_PLUGIN_DIR.'/styles/';
	
	if ($handle = opendir($path)) { 
		while (false !== ($file = readdir($handle))) { 
			if ( !($file == "." || $file == "..") ) { 
				$folder_array[$folder_count]=$file;
				$folder_count++;
				}
		   } 
		} else {
		  echo "Cannot open: ".$path;
		}
	sort($folder_array); 
	
?>

<script type="text/javascript">
function CheckCurrencyOptions() {

   var chosen=document.getElementById("wpa-currency").value;
   var WPA_activetab=document.getElementById("wpa_activetab");

   if (chosen=="99") {
      WPA_activetab.style.display = "";
   } else {
      WPA_activetab.style.display = "none";   
   }

}
function CheckIncrementOptions() {

   var chosen=document.getElementById("wpa-bidincrement").value;
   var WPA_activetab=document.getElementById("wpa_incrementtab");

   if (chosen=="2") {
      WPA_activetab.style.display = "";
   } else {
      WPA_activetab.style.display = "none";   
   }

}
</script>

<div class="wrap"> 
  <form name="form1" method="post" action="<?php admin_url('admin.php?page='.WPA_PLUGIN_NAME); ?>">
  
  <?php wp_nonce_field('WPA-nonce'); ?>
  
  <h2 class="settings"><em><?php _e('General Settings','wpauctions') ?></em></h2>

    <table width="100%" cellspacing="2" cellpadding="5" class="widefat" style="margin-top: 1em;"> 
      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title'><?php _e('Auction Title:','wpauctions') ?></th> 
        <td class='desc'><input name="wpa-title" type="text" id="wpa-title" value="<?php echo $title; ?>" size="40" />
        <br />
        <p><?php _e('Enter header title for your auctions','wpauctions') ?></p></td> 
      </tr> 
      <tr valign="top"> 
        <th scope="row" class='row-title'><?php _e('Currency:','wpauctions') ?></th> 
        <td class='desc'>
        <select id="wpa-currency" name="wpa-currency" onclick="CheckCurrencyOptions()">
                <option value="1" <?php if ($currency=='1') echo 'selected'; ?>>GBP</option>
                <option value="2" <?php if ($currency=='2') echo 'selected'; ?>>USD</option>
                <option value="3" <?php if ($currency=='3') echo 'selected'; ?>>EUR</option>
                <option value="4" <?php if ($currency=='4') echo 'selected'; ?>>JPY</option>
                <option value="5" <?php if ($currency=='5') echo 'selected'; ?>>AUD</option>
                <option value="6" <?php if ($currency=='6') echo 'selected'; ?>>CAD</option>
                <option value="7" <?php if ($currency=='7') echo 'selected'; ?>>NZD</option>
                <option value="8" <?php if ($currency=='8') echo 'selected'; ?>>CHF</option>
                <option value="9" <?php if ($currency=='9') echo 'selected'; ?>>SGD</option>
                <option value="99" <?php if ($currency=='99') echo 'selected'; ?>>Custom</option>
         </select>
        <br />
        <div id="wpa_activetab" style="display:<?php if ($currency!='99'){ echo "none"; }?>;">
          <div style="float:right; border: 2px solid red; color: #000; width: 300px;margin: -5px 10px 15px 0; padding: 5px;"><strong><u><p><?php _e('Warning!','wpauctions') ?></u></strong> <?php _e('If you use a custom currency, please remember that PayPal only supports a','wpauctions') ?> <a href="https://www.paypal.com/us/cgi-bin/webscr?cmd=p/sell/mc/mc_intro-outside"><?php _e('small subset of currencies','wpauctions') ?></a>. <?php _e('If you use a currency outside this set, any PayPal payments will fail.','wpauctions') ?></p> <p><?php _e('You can still use Bank Payments and send your Address for cheques/money orders etc...','wpauctions') ?></p></div>
          <div><?php _e('Currency Code:','wpauctions') ?> <input name="wpa-currencycode" type="text" id="wpa-currencycode" value="<?php echo $currencycode; ?>" size="5" /><br/>
          <?php _e('Currency Symbol:','wpauctions') ?> <input name="wpa*-currencysymbol" type="text" id="wpa-currencysymbol" value="<?php echo $currencysymbol; ?>" size="5" /></div>
        </div>
        <p><?php _e('Choose the currency you would like to run your auctions in','wpauctions') ?></p><p><a href="http://en.wikipedia.org/wiki/List_of_circulating_currencies" target="_blank"><?php _e('Click here for custom Currency Codes and Symbols','wpauctions') ?></a>.</p></td> 
      </tr> 
      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title'><?php _e('Bid Increment:','wpauctions') ?></th> 
        <td class='desc'>
        <select id="wpa-bidincrement" name="wpa-bidincrement" onclick="CheckIncrementOptions()">
                <option value="1" <?php if ($customincrement=='') echo 'selected'; ?>><?php _e('Standard','wpauctions') ?></option>
                <option value="2" <?php if ($customincrement!='') echo 'selected'; ?>><?php _e('Custom','wpauctions') ?></option>
         </select>
        <br />
        <div id="wpa_incrementtab" style="display:<?php if ($customincrement==''){ echo "none"; }?>;">
          <div><?php _e('Your increment amount:','wpauctions') ?><br /><input name="wpa-customincrement" type="text" id="wpa-customincrement" value="<?php echo $customincrement; ?>" size="5" /></div>
        </div>
        <p><?php _e('If you want to override the custom automatic increments, you can specify a custom increment here. This defines what the next bid value would be.','wpauctions') ?></p></td> 
      </tr> 
      <tr valign="top"> 
        <th scope="row" class='row-title'><?php _e('Bid Notification:','wpauctions') ?></th> 
        <td class='desc'><input name="wpa-notify" type="text" id="wpa-notify" value="<?php echo $notify; ?>" size="40" />
        <br />
        <p><?php _e('Enter your email address if you want to be notified whenever a new bid is placed','wpauctions') ?></p></td> 
      </tr> 
      <tr valign="top"> 
        <th scope="row" class='row-title'><?php _e('Other Auctions:','wpauctions') ?></th> 
        <td class='desc'>
        <select id="wpa-otherauctions" name="wpa-otherauctions">
                <option value="1" <?php if ($otherauctions=='1') echo 'selected'; ?>>1</option>
                <option value="2" <?php if ($otherauctions=='2') echo 'selected'; ?>>2</option>
                <option value="3" <?php if ($otherauctions=='3') echo 'selected'; ?>>3</option>
                <option value="4" <?php if ($otherauctions=='4') echo 'selected'; ?>>4</option>
                <option value="5" <?php if ($otherauctions=='5') echo 'selected'; ?>>5</option>
                <option value="6" <?php if ($otherauctions=='6') echo 'selected'; ?>>6</option>
                <option value="7" <?php if ($otherauctions=='7') echo 'selected'; ?>>7</option>
                <option value="8" <?php if ($otherauctions=='8') echo 'selected'; ?>>8</option>
                <option value="9" <?php if ($otherauctions=='9') echo 'selected'; ?>>9</option>
                <option value="all" <?php if ($otherauctions=='all') echo 'selected'; ?>>All</option>
         </select>
        <br />
        <p><?php _e('How many other auctions would you like to display in the widget?','wpauctions') ?></p></td> 
      </tr> 

      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title' style="border-bottom: 0;"><?php _e('Registered Users Only?','wpauctions') ?></th> 
        <td class='desc' style="border-bottom: 0;">
        <select id="wpa-regonly" name="wpa-regonly">
                <option value="" <?php if ($regonly=='') echo 'selected'; ?>><?php _e('No, anyone can bid','wpauctions') ?></option>
                <option value="Yes" <?php if ($regonly=='Yes') echo 'selected'; ?>><?php _e('Yes, only registered users can bid','wpauctions') ?></option>
         </select>
        <br />
        <p><?php _e('If you select Yes, please visit your Settings > General panel you must check the "Anyone can register" box and set the new user role as a subscriber.','wpauctions') ?></p></td> 
      </tr>

    </table>

  <h2 class="payment"><em><?php _e('Payment Settings - Please supply at least one of the following','wpauctions') ?></em></h2>

    <table width="100%" cellspacing="2" cellpadding="5" class="widefat" style="margin-top: 1em;"> 
      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title'><?php _e('PayPal account:','wpauctions') ?></th> 
        <td class='desc'><input name="wpa-paypal" type="text" id="wpa-paypal" value="<?php echo $paypal; ?>" size="40" />
        <br />
        <p><?php _e('Enter your PayPal email address (where you want auction winners to pay for their items)','wpauctions') ?></p></td> 
      </tr> 
      <tr valign="top"> 
        <th scope="row" class='row-title'><?php _e('Bank Details:','wpauctions') ?></th> 
        <td class='desc'>
        <textarea rows="5" cols="100" id="wpa-bankdetails" name="wpa-bankdetails"><?php echo $bankdetails; ?></textarea>
        <br />
        <p><?php _e('Enter your bank details (where you want auction winners to wire tranfers to you)','wpauctions') ?></p></td> 
      </tr> 
      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title' style="border-bottom: 0;"><?php _e('Mailing Address:','wpauctions') ?></th> 
        <td class='desc' style="border-bottom: none;">
        <textarea rows="5" cols="100" id="wpa-mailingaddress" name="wpa-mailingaddress"><?php echo $mailingaddress; ?></textarea>
        <br />
        <p><?php _e('Enter your mailing address address (where you want auction winners to mail you cheques and money orders)','wpauctions') ?></p></td> 
      </tr> 
    </table>

  <h2 class="other-settings"><em><?php _e('Other Settings','wpauctions') ?></em></h2> 

    <table width="100%" cellspacing="2" cellpadding="5" class="widefat" style="margin-top: 1em;"> 
      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title'><?php _e('Style:','wpauctions') ?></th> 
        <td class='desc'>
           <select id="wpa-style" name="wpa-style">
            <?php                           
               foreach ($folder_array as $thisstyle) {
			      echo '<option value="'.$thisstyle.'"';
			      if ($thisstyle == $style) 
				     echo ' selected ';
			      echo '>'.$thisstyle;
			      echo '</option>';
		       } ?>
            </select>
        <br />
        <p><?php _e('Choose a graphical style for your widget.','wpauctions') ?></p></td> 
      </tr> 
      <tr valign="top"> 
        <th scope="row" class='row-title'><?php _e('List Format:','wpauctions') ?></th> 
        <td class='desc'>
        <select id="wpa-list" name="wpa-list">
                <option value="" <?php if ($list=='') echo 'selected'; ?>><?php _e('No, I prefer a graphical format','wpauctions') ?></option>
                <option value="Yes" <?php if ($list=='Yes') echo 'selected'; ?>><?php _e('Yes, show auctions in list format','wpauctions') ?></option>
         </select>
        <br />
        <p><?php _e('Select whether you prefer the sidebar widget to show a graphical or list format','wpauctions') ?></p></td> 
      </tr>
       
      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title'><?php _e('"No Auction" Alternative:','wpauctions') ?></th> 
        <td class='desc'>
        <textarea rows="5" cols="100" id="wpa-noauction" name="wpa-noauction"><?php echo $noauction; ?></textarea>
        <br />
        <p><?php _e('Specify the HTML you would like to display if there are no active auctions. Leave blank for standard "No Auctions" display<br>To rotate ads, separate with &lt;!--more--&gt;','wpauctions') ?></p></td> 
      </tr> 

      <tr valign="top"> 
        <th scope="row" class='row-title'><?php _e('Custom Contact Field:','wpauctions') ?></th> 
        <td class='desc'><input name="wpa-customcontact" type="text" id="wpa-customcontact" value="<?php echo $customcontact; ?>" size="10" />
        <br />
        <p><?php _e('Enter your custom contact field caption (leave blank for URL <- this is the default setting)','wpauctions') ?></p></td> 
      </tr>
      <tr valign="top"> 
        <th scope="row" class='row-title'><?php _e('RSS Feed link:','wpauctions') ?></th> 
        <td class='desc'>
        <select id="wpa-showrss" name="wpa-showrss">
                <option value="No" <?php if ($showrss=='No') echo 'selected'; ?>><?php _e('Hide RSS link','wpauctions') ?></option>
                <option value="" <?php if ($showrss=='') echo 'selected'; ?>><?php _e('Show RSS link','wpauctions') ?></option>
         </select>
        <br />
        <p><?php _e('Do you want to publish a link to your auction RSS feed. This can let people know when you publish new auctions','wpauctions') ?></p></td> 
      </tr> 

    </table>

<?php 	do_action('wpa_options_form'); ?>

	<input type="hidden" id="wp_auctions-submit" name="wp_auctions-submit" value="1" />

    <p>
      <input type="submit" name="Submit" class="button add-auction" value="<?php _e('Update Options','wpauctions') ?> &raquo;" />
    </p>
  </form> 
</div>

<?php
}


function wp_auctions_welcome() {

global $wpa_version;
global $wp_version;

// first let's check if database is update date
wp_auctions_install();

include_once( ABSPATH . WPINC . '/feed.php' );

// Get a SimplePie feed object from the specified feed source.
$rss = fetch_feed( 'http://www.wpauctions.com/feed/' );

$maxitems = 0;

if ( ! is_wp_error( $rss ) ) : // Checks that the object is created correctly

    // Figure out how many total items there are, but limit it to 5. 
    $maxitems = $rss->get_item_quantity( 5 ); 

    // Build an array of all the items, starting with element 0 (first element).
    $rss_items = $rss->get_items( 0, $maxitems );

endif;
?>

<link href="../wp-content/plugins/wp-auctions/requisites/style.css" rel="stylesheet" type="text/css" />

<div class="wrap wp-auctions">
			
	<div class="wpa-update-screen">
		<h3><span style="color: #D54E21;">Upgrade to WP Auctions Pro</span></h3> 
		<p><strong>Features:</strong> <em>3 Bidding Engines</em> &mdash; <em>Add Auction Fees</em> &mdash; <em>Responsive Design</em> &mdash; ...and much more!</p>
		<p><a href="http://www.wpauctions.com/download" class="button button-primary">Only <del>$59</del> <strong style="text-decoration: underline;">$45</strong>, click for Instant Download</a></p>
	</div>

	<div class="wpa-intro">

 	<p><?php _e('Version:','wpauctions') ?> <?php echo $wpa_version ?></p>

	<div class="latestnews">
        <h3><?php _e('WP Auctions Pro News','wpauctions') ?></h3>

<ul>
    <?php if ( $maxitems == 0 ) : ?>
        <li><?php _e( 'No news found', 'my-text-domain' ); ?></li>
    <?php else : ?>
        <?php // Loop through each feed item and display each item as a hyperlink. ?>
        <?php foreach ( $rss_items as $item ) : ?>
            <li>
                <a href="<?php echo esc_url( $item->get_permalink() ); ?>"
                    title="<?php printf( __( 'Posted %s', 'my-text-domain' ), $item->get_date('j F Y | g:i a') ); ?>">
                    <?php echo esc_html( $item->get_title() ); ?>
                </a>
            </li>
        <?php endforeach; ?>
    <?php endif; ?>
</ul>

    </div>

    <div class="wpa-info">
    	<h3><?php _e('Resources','wpauctions') ?></h3>
      		<p><a href="https://wordpress.org/support/plugin/wp-auctions"><?php _e('Support','wpauctions') ?></a></p>
	  	<h3 class="wpa-upgrade"><?php _e('Leave a Rating','wpauctions'); ?></h3>
	  		<p style="padding-bottom: 0; margin-bottom: 0;"><?php _e('Your ratings make us develop awesome features! Leave yours on ','wpauctions'); ?> - <a href="https://wordpress.org/support/view/plugin-reviews/wp-auctions"><?php _e('WordPress.org','wpauctions'); ?></a></p>
			<p style="padding-bottom: 0; margin-bottom: 0;"><img src="../wp-content/plugins/wp-auctions/requisites/star.png" width="16" height="16"/><img src="../wp-content/plugins/wp-auctions/requisites/star.png" width="16" height="16"/><img src="../wp-content/plugins/wp-auctions/requisites/star.png" width="16" height="16"/><img src="../wp-content/plugins/wp-auctions/requisites/star.png" width="16" height="16"/><img src="../wp-content/plugins/wp-auctions/requisites/star.png" width="16" height="16"/></p>
    </div>

    <div style="clear:both"></div>
	
</div>
	
<h2><?php _e('Get Started:','wpauctions'); ?></h2>

<ul class="wpa-start">
	<li><div class="buttons"><button onclick="window.location = 'admin.php?page=wp-auctions-add';" class="button"><strong><?php _e('Add An Auction','wpauctions'); ?></strong></button></div></li>
    <li><div class="buttons">/ &nbsp;<button onclick="window.location = 'admin.php?page=wp-auctions-manage';" class="button"><strong><?php _e('Manage Auctions','wpauctions'); ?></strong></button></div></li>
	<li><div class="buttons wpa-upgrade">/ &nbsp;<button onclick="window.location = 'https://www.e-junkie.com/ecom/gb.php?i=WPAPLUS&c=single&cl=16004';" class="button"><strong>Upgrade Plugin</strong></button></div></li>
</ul>
<div style="clear:both"></div>

<?php wp_auctions_options();  ?>

</div>

<?php   
}


function wpa_resetgetvars()
{
	unset($GLOBALS['_GET']["wpa_action"]);
	unset($GLOBALS['_GET']["wpa_id"]);
}

function wpa_chkfields($strName, $strDescription,$strEndDate)
{
	if($strName == "" || $strDescription == "" || $strEndDate == ""):
		$bitError = 1;
	endif;
	return $bitError;
}

function wpa_chkPrices($StartPrice, $ReservePrice,$BINPrice)
{
  if (($StartPrice < 0.01) && ($BINPrice <0.01)):
		$bitError = 1;
	elseif($ReservePrice > 0 && ($ReservePrice - $StartPrice) < 0):
		$bitError = 1;
	elseif($BINPrice > 0 && ($BINPrice - $StartPrice) < 0):
		$bitError = 1;
	endif;
	
	return $bitError;
}

function wp_auctions_add() {

   global $wpdb;
   $table_name = $wpdb->prefix . "wpa_auctions";

   $options = get_option('wp_auctions');
   $paypal = $options['paypal'];
   $mailingaddress = $options['mailingaddress'];
   $bankdetails = $options['bankdetails'];
   $customincrement = $options['customincrement'];
     
   // Primary action
   if(isset($_REQUEST["wpa_action"])):

      // security check
      check_admin_referer( 'WPA-nonce');
      

      if($_POST["wpa_action"] == "Add Auction"):
         $strSaveName = strip_tags(htmlspecialchars($_POST["wpa_name"]));
         $strSaveDescription = $_POST["wpa_description"];
         $strSaveStartPrice = $_POST["wpa_StartPrice"];
         $strSaveReservePrice = $_POST["wpa_ReservePrice"];
         $strSaveBINPrice = $_POST["wpa_BINPrice"];
         $strSaveEndDate = $_POST["wpa_EndDate"];
         $strSaveShippingPrice = $_POST["wpa_ShippingPrice"];
         $strSaveShippingTo = strip_tags(htmlspecialchars($_POST["wpa_ShippingTo"]));
         $strSaveShippingFrom = strip_tags(htmlspecialchars($_POST["wpa_ShippingFrom"]));                           
         $strStaticPage = $_POST["wpa_StaticPage"];     
         $strPaymentMethod = $_POST["wpa_PaymentMethod"];
         
         $strSaveImageURL = $_POST["wpa_ImageURL"];
		 $strSaveImageURL1 = $_POST["wpa_ImageURL1"];
		 $strSaveImageURL2 = $_POST["wpa_ImageURL2"];
		 $strSaveImageURL3 = $_POST["wpa_ImageURL3"];
                       
      elseif($_POST["wpa_action"] == "Update Auction"):
      
      
         $strUpdateID = $_POST["wpa_id"];
         $strSaveName = strip_tags(htmlspecialchars($_POST["wpa_name"]));
         $strSaveDescription = $_POST["wpa_description"];
         $strSaveStartPrice = $_POST["wpa_StartPrice"];
         $strSaveReservePrice = $_POST["wpa_ReservePrice"];
         $strSaveBINPrice = $_POST["wpa_BINPrice"];
         $strSaveEndDate = $_POST["wpa_EndDate"];
         $strSaveShippingPrice = $_POST["wpa_ShippingPrice"];
         $strSaveShippingTo = strip_tags(htmlspecialchars($_POST["wpa_ShippingTo"]));
         $strSaveShippingFrom = strip_tags(htmlspecialchars($_POST["wpa_ShippingFrom"]));
         $strStaticPage = $_POST["wpa_StaticPage"];
         $strPaymentMethod = $_POST["wpa_PaymentMethod"]; 
         
         $strSaveImageURL = $_POST["wpa_ImageURL"];
		 $strSaveImageURL1 = $_POST["wpa_ImageURL1"];
		 $strSaveImageURL2 = $_POST["wpa_ImageURL2"];
		 $strSaveImageURL3 = $_POST["wpa_ImageURL3"];
		 
		 //echo "Post Popup - " . $_POST["popup"] ;
		 
		 //tidy up
		 if ( $_POST["popup"] == "No" ) { $strStaticPage = "";   }
                      
         $bolUpdate = true;
      elseif($_GET["wpa_action"] == "edit"):
         $strSQL = "SELECT * FROM ".$table_name." WHERE id=".$_GET["wpa_id"];
         $resultEdit = $wpdb->get_row($strSQL);
         $strUpdateID = $_GET["wpa_id"];
         $strSaveName = htmlspecialchars_decode($resultEdit->name, ENT_NOQUOTES);
         $strSaveDescription = stripslashes($resultEdit->description);
         $strSaveImageURL = $resultEdit->image_url;
         $strSaveStartPrice = $resultEdit->start_price;
         $strSaveReservePrice = $resultEdit->reserve_price;
         $strSaveBINPrice = $resultEdit->BIN_price;
         $strSaveEndDate = get_date_from_gmt($resultEdit->date_end);
         $strSaveShippingPrice = $resultEdit->shipping_price;
         $strSaveShippingFrom = htmlspecialchars_decode($resultEdit->shipping_from, ENT_NOQUOTES);
         $strSaveShippingTo = htmlspecialchars_decode($resultEdit->shipping_to, ENT_NOQUOTES);                  
         $strSaveImageURL1 = $resultEdit->extraimage1;
         $strSaveImageURL2 = $resultEdit->extraimage2;
         $strSaveImageURL3 = $resultEdit->extraimage3;
         $strStaticPage = $resultEdit->staticpage;
         $strPaymentMethod = $resultEdit->paymentmethod;
         $bolUpdate = true;
         wpa_resetgetvars();
      elseif($_GET["wpa_action"] == "relist"):
         $strSQL = "SELECT * FROM ".$table_name." WHERE id=".$_GET["wpa_id"];
         $resultList = $wpdb->get_row($strSQL);
         $strSaveName = htmlspecialchars_decode($resultList->name, ENT_NOQUOTES);
         $strSaveDescription = stripslashes($resultList->description);
         $strSaveImageURL = $resultList->image_url;
         $strSaveStartPrice = $resultList->start_price;
         $strSaveReservePrice = $resultList->reserve_price;
         $strSaveBINPrice = $resultList->BIN_price;
         $strSaveEndDate = get_date_from_gmt($resultList->date_end);
         $strSaveShippingPrice = $resultEdit->shipping_price;
         $strSaveShippingFrom = htmlspecialchars_decode($resultEdit->shipping_from, ENT_NOQUOTES);
         $strSaveShippingTo = htmlspecialchars_decode($resultEdit->shipping_to, ENT_NOQUOTES);                  
         $strSaveImageURL1 = $resultList->extraimage1;
         $strSaveImageURL2 = $resultList->extraimage2;
         $strSaveImageURL3 = $resultList->extraimage3;
         $strStaticPage = $resultList->staticpage;
         $strPaymentMethod = $resultList->paymentmethod;
         wpa_resetgetvars();
      endif;
   endif;

   // Validation & Save
   if($_POST["wpa_action"] == "Add Auction"):
      if(wpa_chkfields($strSaveName, $strSaveDescription,$strSaveEndDate)==1):
         $strMessage = __('Please fill out all fields.','wpauctions');
      elseif(strtotime($strSaveEndDate) < strtotime(get_date_from_gmt(date('Y-m-d H:i:s')))):      
         $strMessage = __('Auction end date/time cannot be in the past','wpauctions').": (Specified: ".$strSaveEndDate." - Current: ".get_date_from_gmt(date('Y-m-d H:i:s')).")";
      elseif(wpa_chkPrices($strSaveStartPrice,$strSaveReservePrice,$strSaveBINPrice) == 1):
         $strMessage = __('Starting Price must be numeric and less than Reserve and BIN Prices','wpauctions');
      endif;

      if ($strMessage == ""):
         // force reserve value (not implemented),BINPrice and Shipping Price to ensure value written in InnoDB (which doesn't like Null decimals)
         $strSaveReservePrice = 0;
         $strSaveDuration = 0;  // depracated
         $strSaveBINPrice = $strSaveBINPrice + 0;
         $strSaveShippingPrice = $strSaveShippingPrice + 0;

         // convert date/time to GMT
         
         $strSaveEndDate2 = get_gmt_from_date($strSaveEndDate);
         $GMTTime = current_time('mysql',"1");
         
         // defensive check
         if ($strSaveEndDate2 != '1970-01-01 00:00:00') {
	         $strSaveEndDate = $strSaveEndDate2;
         }

         $strSQL = "INSERT INTO $table_name (date_create,date_end,name,description,image_url,start_price,reserve_price,BIN_price,duration,shipping_price,shipping_from,shipping_to,extraimage1,extraimage2,extraimage3,staticpage,paymentmethod) VALUES('".$GMTTime."','".$strSaveEndDate."','".$strSaveName."','".$strSaveDescription."','".$strSaveImageURL."','".$strSaveStartPrice."','".$strSaveReservePrice."','".$strSaveBINPrice."','".$strSaveDuration."','".$strSaveShippingPrice."','".$strSaveShippingFrom."','".$strSaveShippingTo."','".$strSaveImageURL1."','".$strSaveImageURL2."','".$strSaveImageURL3."','".$strStaticPage."','".$strPaymentMethod."')";
         
         // defensive check to make sure noone's put "|" in any field (as this breaks AJAX)
         $strSQL = str_replace( "|" , "" , $strSQL );
         
         $wpdb->query($strSQL);
         $strMessage = __('Auction added','wpauctions');
         $strSaveName = "";
         $strSaveDescription = "";
         $strSaveImageURL = "";
         $strSaveStartPrice = "";
         $strSaveReservePrice = "";
         $strSaveBINPrice = "";
         $strSaveDuration = "";
         $strStaticPage = "";
         $strSaveEndDate = "";
         $strSaveShippingPrice = "";
         $strSaveShippingFrom = "";
         $strSaveShippingTo = "";
         $strSaveImageURL1 = "";
         $strSaveImageURL2 = "";
         $strSaveImageURL3 = "";
         $strPaymentMethod = "";
         
      endif;
      wpa_resetgetvars();
   elseif($_POST["wpa_action"] == "Update Auction"):
      if(wpa_chkfields($strSaveName, $strSaveDescription,$strSaveStartPrice,$strSaveDuration)==1):
         $strMessage = __('Please fill out all fields.','wpauctions');
      elseif(strtotime($strSaveEndDate) < strtotime(get_date_from_gmt(date('Y-m-d H:i:s')))):      
         $strMessage = __('Auction end date/time cannot be in the past','wpauctions').": (Specified: ".$strSaveEndDate." - Current: ".get_date_from_gmt(date('Y-m-d H:i:s')).")";
      elseif(wpa_chkPrices($strSaveStartPrice,$strSaveReservePrice,$strSaveBINPrice) == 1):
         $strMessage = __('Starting Price must be numeric and less than Reserve and BIN Prices','wpauctions');
      endif;

      if ($strMessage == ""):
         // force reserve value (not implemented),BINPrice and Shipping Price to ensure value written in InnoDB (which doesn't like Null decimals)
         $strSaveReservePrice = 0;
         $strSaveDuration = 0;  // depracated
         $strSaveBINPrice = $strSaveBINPrice + 0;
         $strSaveShippingPrice = $strSaveShippingPrice + 0;

         // convert date/time to machine
         $strSaveEndDate2 = get_gmt_from_date($strSaveEndDate);

         // defensive check
         if ($strSaveEndDate2 != '1970-01-01 00:00:00') {
	         $strSaveEndDate = $strSaveEndDate2;
         }

         $strSQL = "UPDATE $table_name SET name='$strSaveName', description = '$strSaveDescription', image_url = '$strSaveImageURL', start_price = '$strSaveStartPrice', reserve_price = '$strSaveReservePrice', BIN_price = '$strSaveBINPrice', duration = '$strSaveDuration', shipping_price = '$strSaveShippingPrice', shipping_from = '$strSaveShippingFrom', shipping_to = '$strSaveShippingTo', date_end = '$strSaveEndDate', extraimage1 = '$strSaveImageURL1', extraimage2 = '$strSaveImageURL2', extraimage3 = '$strSaveImageURL3', staticpage = '$strStaticPage', paymentmethod = '$strPaymentMethod' WHERE id=" . $_POST["wpa_id"];

         // defensive check to make sure noone's put "|" in any field (as this breaks AJAX)
         $strSQL = str_replace( "|" , "" , $strSQL );

         //echo $strSQL;
         
		 $strMessage = "Auction updated";
         //$bolUpdate = false;
         
         $wpdb->query($strSQL);
         wpa_resetgetvars();
      endif;
   endif;
			
   ?>
   
   <link href="../wp-content/plugins/wp-auctions/requisites/style.css" rel="stylesheet" type="text/css" />
   
	<div class="wrap wp-auctions">
		
		<div class="wpa-update-screen">
			<h3><span style="color: #D54E21;">Upgrade to WP Auctions Pro:</span></h3> 
			<p><strong>Features:</strong> <em>Set a Buy it Now price</em> &mdash; <em>Set a reserve price</em> &mdash; <em>Set a custom bid increment amount</em></p>
			<p><a href="http://www.wpauctions.com/download" class="button button-primary">Only <del >$59</del> <strong style="text-decoration: underline;">$45</strong>, click to purchase</a></p>
		</div>
	
		<?php if($strMessage != ""):?>
			<fieldset class="options">
				<legend><?php _e('Information','wpauctions'); ?></legend>
				<p><font color=red><strong><?php print $strMessage ?></strong></font></p>
			</fieldset>
		<?php endif; ?>
		
		<h2 class="details"><em><?php _e('Auction Details','wpauctions'); ?></em></h2>

		<script language="Javascript">
		
		function showhide(){
		   var dropdown = jQuery("#popup").val();   
		   
		   if (dropdown == "No") {
			  jQuery("#optional_static_page").hide();
		   } else {
			  jQuery("#optional_static_page").show();
		   }      
		}
		
		// show/hide optional element
		jQuery(document).ready(function() {
		  showhide();
		  
		  // set up datepicker
		  jQuery("#wpa_EndDate").datetimepicker({ dateFormat: 'yy-mm-dd', timeFormat: ' hh:mm:ss' });        
		  
		});
		
		//image handler
		jQuery(document).ready(function($){
		  var _custom_media = true,
		      _orig_send_attachment = wp.media.editor.send.attachment;
		
		  $('.uploader_button').click(function(e) {
		    var send_attachment_bkp = wp.media.editor.send.attachment;
		    var button = $(this);
		   
		    var id = button.attr('id').replace('_button', '');
		    _custom_media = true;
		    wp.media.editor.send.attachment = function(props, attachment){
		      if ( _custom_media ) {
		      
		       $("#"+id+"_image").html('<img src="' + attachment.url + '"  height=125 />');
		      
		        $("#"+id).val(attachment.id);
		      } else {
		        return _orig_send_attachment.apply( this, [props, attachment] );
		      };
		    }
		
		    wp.media.editor.open(button);
		    return false;
		  });
		
		  $('.add_media').on('click', function(){
		    _custom_media = false;
		  });
		});
		
		
		</script>

		<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=wp-auctions-add" id="editform" enctype="multipart/form-data">

    <?php wp_nonce_field('WPA-nonce'); ?>

    <table width="100%" cellspacing="2" cellpadding="5" class="widefat"> 
      <tr valign="top" class="alternate"> 
        <th scope="row"><?php _e('Title:','wpauctions') ?></th> 
        <td><input type="text" name="wpa_name" value="<?php print $strSaveName ?>" maxlength="255" size="50" /><br>
        <?php _e('Specify the title for your auction.','wpauctions') ?></td> 
      </tr>
      <tr valign="top"> 
        <th scope="row"><?php _e('Description:','wpauctions') ?></th> 
        <td>
        
        <?php

		$content = $strSaveDescription;
		$id = 'wpa_description';
		$settings = array(
			'quicktags' => array(
				'buttons' => 'em,strong,link',
			),
			'quicktags' => true,
			'media_buttons' => false,
			'tinymce' => true,
			'height' => 100
		);

		wp_editor($content, $id, $settings);
?>        
        
         <br>
        <p><?php _e('Specify the description for your auction.','wpauctions') ?></p>
		<p><?php _e('You can even include a video!','wpauctions') ?><strong> <?php _e('Important: Video width and height MUST be width="324" height="254"','wpauctions') ?></strong></p></td> 
      </tr>
      <tr valign="top" class="alternate"> 
        <th scope="row"><?php _e('Primary Image:','wpauctions') ?></th> 
        <td>

		  <?php _e('Select an image:','wpauctions') ?>		 
		  <div id="wpa_ImageURL_image" style="float:right;">
		  	<img src="<?php echo wpa_resize ( $strSaveImageURL, 125 ) ?>" width="125px" height="125px">
		  </div>
		
		  <input type="hidden" name="wpa_ImageURL" id="wpa_ImageURL" value="<?php echo $strSaveImageURL ?>"/>
		  <input class="uploader_button button" type="button" name="wpa_ImageURL_button" id="wpa_ImageURL_button" value="<?php _e('Upload','wpauctions') ?>" />

        </td> 
      </tr>
      <tr valign="top"> 
        <th scope="row"><?php _e('Start Price:','wpauctions') ?></th> 
        <td><input type="text" name="wpa_StartPrice" value="<?php print $strSaveStartPrice ?>" maxlength="255" size="10" /><br>
        <?php _e('Specify the starting price for your auction. Leave empty (or 0) for Fixed Price BIN','wpauctions') ?>
        <?php if (!empty($customincrement)) { echo '<br>'; _e('Remember that you have configured bidding in increments of ','wpauctions'); echo $customincrement; } ?>
        </td> 
      </tr>
      <tr valign="top" class="alternate"> 
        <th scope="row"><?php _e('End Date:','wpauctions') ?></th> 
        <td><input type="text" name="wpa_EndDate" id="wpa_EndDate" value="<?php print $strSaveEndDate ?>" maxlength="20" size="20" /><br>
        <?php _e('When would you like this auction to end? Note that blog time is: ','wpauctions'); echo get_date_from_gmt(date('Y-m-d H:i:s')); ?></td> 
      </tr>
      <tr valign="top"> 
        <th scope="row" style="border-bottom: 0;"><?php _e('Payment Method:','wpauctions') ?></th> 
        <td style="border-bottom: 0;">
           <input name="wpa_PaymentMethod" id="wpa-radio" type="radio" value="paypal" <?php if ($strPaymentMethod=="paypal") echo "CHECKED";?> <?php if ($paypal=="") echo "DISABLED";?>>PayPal<br>
           <input name="wpa_PaymentMethod" id="wpa-radio" type="radio" value="bankdetails" <?php if ($strPaymentMethod=="bankdetails") echo "CHECKED";?> <?php if ($bankdetails=="") echo "DISABLED";?>><?php _e('Wire Transfer','wpauctions') ?><br>        
           <input name="wpa_PaymentMethod" id="wpa-radio" type="radio" value="mailingaddress" <?php if ($strPaymentMethod=="mailingaddress") echo "CHECKED";?> <?php if ($mailingaddress=="") echo "DISABLED";?>><?php _e('Cheque or Money Order','wpauctions') ?><br>        
        <?php _e('Specify the payment method from this auction (Only options you filled on the Configuration screen are available)','wpauctions') ?></td> 
      </tr>
     </table>


   <h2 class="shipping"><em><?php _e('Shipping Information','wpauctions') ?></em></h2>
    <table width="100%" cellspacing="2" cellpadding="5" class="widefat"> 
      <tr valign="top" class="alternate"> 
        <th scope="row"><?php _e('Shipping Price:','wpauctions') ?></th> 
        <td><input type="text" name="wpa_ShippingPrice" value="<?php print $strSaveShippingPrice ?>" maxlength="255" size="10" /><br>
        <?php _e('How much would you like to charge for shipping?','wpauctions') ?></td> 
      </tr>
      <tr valign="top"> 
        <th scope="row"><?php _e('Shipping To:','wpauctions') ?></th> 
        <td><input type="text" name="wpa_ShippingTo" value="<?php print $strSaveShippingTo ?>" maxlength="255" size="50" /><br>
        <?php _e('Where are you prepared to ship this item to?','wpauctions') ?></td> 
      </tr>
      <tr valign="top" class="alternate"> 
        <th scope="row" style="border-bottom: 0;"><?php _e('Shipping From:','wpauctions') ?></th> 
        <td style="border-bottom: 0;"><input type="text" name="wpa_ShippingFrom" value="<?php print $strSaveShippingFrom ?>" maxlength="255" size="50" /><br>
        <?php _e('Where are you shipping this item from?','wpauctions') ?></td> 
      </tr>
   </table>

   
  <h2 class="other-settings"><em><?php _e('Optional Settings','wpauctions') ?></em></h2>
    <table width="100%" cellspacing="2" cellpadding="5" class="widefat"> 
    <!--    W6 - Test BIN pricing before releasing
	
       <tr valign="top" class="alternate"> 
        <th scope="row"><?php _e('Buy It Now Price:','wpauctions') ?></th> 
        <td><input type="text" name="wpa_BINPrice" value="<?php print $strSaveBINPrice ?>" maxlength="255" size="10" />
        <?php _e('Specify the "Buy It Now" price for your auction.','wpauctions') ?></td> 
      </tr>
      -->
      <tr valign="top"> 
        <th scope="row"><?php _e('Extra Image:','wpauctions') ?></th> 
        <td>

		  <?php _e('Select an image:','wpauctions') ?>		 
		  <div id="wpa_ImageURL1_image" style="float:right;">
		  	<img src="<?php echo wpa_resize ( $strSaveImageURL1, 125 ) ?>" width="125px" height="125px">
		  </div>
		
		  <input type="hidden" name="wpa_ImageURL1" id="wpa_ImageURL1"  value="<?php echo $strSaveImageURL1 ?>" />
		  <input class="uploader_button button" type="button" name="wpa_ImageURL1_button" id="wpa_ImageURL1_button" value="<?php _e('Upload','wpauctions') ?>" />

        </td>
      </tr>

      <tr valign="top" class="alternate"> 
        <th scope="row" style="border-bottom: 0;">
        <?php _e('Show auction in AJAX Popup?:','wpauctions') ?></th> 
        <td style="border-bottom: 0;">        
         <select id="popup" name="popup" onchange="showhide()">
                <option value="No" <?php if ($strStaticPage=='') echo 'selected'; ?>><?php _e('Yes','wpauctions') ?></option>
                <option value="Yes" <?php if ($strStaticPage!='') echo 'selected'; ?>><?php _e('No, show auction in a post','wpauctions') ?></option>
         </select>
        <br>
        <?php _e('If you don\'t want to use the popup, you can direct the auction to a <a href="edit.php">Post</a> or <a href="edit.php?post_type=page">Page</a> (you\'ll need to add the Auction shortcode to the page)','wpauctions') ?></td> 
      </tr>
      <tr valign="top" id="optional_static_page"> 
        <th scope="row" style="border-bottom: 0;">
        <?php _e('URL for Static Post/Page:','wpauctions') ?> </th> 
        <td style="border-bottom: 0;"><input type="text" name="wpa_StaticPage" value="<?php print $strStaticPage ?>" maxlength="255" size="50" /><br>
        <ol>
			<li><?php _e('Select a published <a href="edit.php" target="_blank">Post</a> or <a href="edit.php?post_type=page" target="_blank">Page</a> URL where this auction will be inserted.','wpauctions') ?></li>
			<li><?php _e('Go to that Post/Page and use the "Insert Auction" panel to embed the auction shortcode.','wpauctions') ?></li></li>
		</ol>	
			</td> 
      </tr>

   	</table>
		
	<?php if($bolUpdate == true): ?>
		<div class="buttons add-auction"><input type="hidden" name="wpa_id" value="<?php echo $strUpdateID ?>"><input type="hidden" name="wpa_action" value=
		"Update Auction">
		<input type="submit" name="wpa_doit" value="Update Auction" class="button"></div>
	<?php else: ?>
		<div class="buttons add-auction"><input type="hidden" name="wpa_action" value="Add Auction"><input type="submit" name="wpa_doit" value="Add Auction &raquo;" class="button" ></div>
	<?php endif; ?>

	</form>
		
	</div>
<?php
}


function wp_auctions_manage() {

   global $wpdb;
   $intAlternate = 0;

   // Primary action
   if(isset($_REQUEST["wpa_action"])):

      // security check
      check_admin_referer( 'WPA-nonce');

      if($_GET["wpa_action"] == "reverse"):
         $intAuctionID = $_GET["wpa_id"];
         $intBidID = $_GET["bid_id"];

         // get ready to reverse the last bid on the auction
     		 $bid_table_name = $wpdb->prefix . "wpa_bids";
     		 $auction_table_name = $wpdb->prefix . "wpa_auctions";

         // Step 1 - Delete Last bid
         $strSQL = "DELETE FROM $bid_table_name WHERE id=" . $intBidID;
         $wpdb->query($strSQL);

         // flush cache .. otherwise we'll just pick up an empty record on the next pass
         $wpdb->flush();

         // Step 2 - Assess highest bid
		     $strSQL = "SELECT * FROM $bid_table_name WHERE auction_id=".$intAuctionID." ORDER BY current_bid_price DESC LIMIT 1";
		     $current = $wpdb->get_row ($strSQL);

         // Step 3 - Update Auction with current bid price
			  $sql = "UPDATE ".$auction_table_name." SET current_price = ".floatval($current->current_bid_price)." WHERE id=".$intAuctionID;
			  $wpdb->query($sql);

      elseif ($_GET["wpa_action"] == "terminate"):
         $intAuctionID = $_GET["wpa_id"];

         // get ready to reverse the last bid on the auction
     		 $auction_table_name = $wpdb->prefix . "wpa_auctions";

         // Step 1 - Update auction to set end timestamp to now
			  $sql = "UPDATE ".$auction_table_name." SET date_end = '".current_time('mysql',"1")."' WHERE id=".$intAuctionID;
			  $wpdb->query($sql);

         // wait a bit, to make sure Now() in termination check doesn't match NOW() here.
         sleep (2);

         // Step 2 - Teminate Auction
         check_auction_end($intAuctionID );  
      elseif($_GET["wpa_action"] == "delete"):
     		 $auction_table_name = $wpdb->prefix . "wpa_auctions";
         $strSQL = "DELETE FROM $auction_table_name WHERE id=" . $_GET["wpa_id"];
         $wpdb->query($strSQL);         
      endif;
   endif;

   $options = get_option('wp_auctions');
   $currencysymbol = $options['currencysymbol'];

   $nonce = wp_create_nonce ('WPA-nonce')

?>
	<link href="../wp-content/plugins/wp-auctions/requisites/style.css" rel="stylesheet" type="text/css" />

	<div class="wrap wp-auctions">
	
	<div class="wpa-update-screen">
		<h3><span style="color: #D54E21;">Exciting new Pro features</span></h3> 
		<p><strong>Features:</strong> <em>Subscriber auctions</em> &mdash; <em>PayPal payment page</em> &mdash; <em>Set terms and conditions</em></p>
		<p><a href="http://www.wpauctions.com/download" class="button button-primary">Go Pro today <del>$59</del> <strong style="text-decoration: underline;">$45</strong>, save $15!</a></p>
	</div>
  		
	<div class="wpa-time"><?php _e('Your WordPress Time:','wpauctions'); ?> <?php echo get_date_from_gmt(date('Y-m-d H:i:s')); ?></div>
	
	<h2 class="manage"><em><?php _e('Manage Auctions','wpauctions'); ?></em></h2>
	
	<fieldset class="options">
	<legend><?php _e('Current Auctions','wpauctions'); ?></legend>
	<?php
		$table_name = $wpdb->prefix . "wpa_auctions";
		$strSQL = "SELECT id, date_create, date_end, name, BIN_price, image_url, current_price FROM $table_name WHERE '".current_time('mysql',"1")."' < date_end ORDER BY date_end DESC";
		$rows = $wpdb->get_results ($strSQL);
		
		$bid_table_name = $wpdb->prefix . "wpa_bids";
	?>
	<table class="widefat">
       <thead>
		<tr>
			<th><?php _e('ID','wpauctions'); ?></th>
			<th><?php _e('Name','wpauctions'); ?></th>
			<th><?php _e('Created/Ending','wpauctions'); ?></th>
			<th><?php _e('Bids','wpauctions'); ?></th>
			<th><?php _e('Current Price','wpauctions'); ?></th>
			<th><?php _e('Thumbnail','wpauctions'); ?></th>
			<th><?php _e('Actions','wpauctions'); ?></th>
		</tr>
       </thead>
	<?php if (is_array($rows)): ?>
		<?php foreach ($rows as $row) { 
             $style=" ";
             if($intAlternate==1) $style=$style."alternate "; 
             if(strtotime($row->date_end)<=strtotime("now")) $style=$style."active ";

             ?>
			<tr<?php if($style!=" "): ?> class="<?php echo $style ?>"<?php endif; ?>>
				<td><?php print $row->id; ?></td>
				<td><?php print $row->name; ?> </td>
				<td><b><?php _e('Created:','wpauctions'); ?></b><br><?php print get_date_from_gmt($row->date_create); ?> <br>
				    <b><?php _e('Ending:','wpauctions'); ?></b><br><?php print get_date_from_gmt($row->date_end); ?></td>
				<td align="center">
<?php

  $bids=0;
					// prepare result
	$strSQL = "SELECT id, bidder_name, bidder_email , bidder_url, date,current_bid_price, bid_type FROM $bid_table_name WHERE auction_id=".$row->id." ORDER BY current_bid_price, bid_type DESC";
	$bid_rows = $wpdb->get_results ($strSQL);
			
	foreach ($bid_rows as $bid_row) {
	   echo ('<a href="mailto:'.$bid_row->bidder_email.'">');
	   echo ($bid_row->bidder_name);
	   echo ('</a> ('.$bid_row->bidder_url.') - '.$currencysymbol.$bid_row->current_bid_price);
	   echo ('['.$bid_row->bid_type.']');
	   echo ('<br>');
	   $bids++;
	}		
	
	if ($bids!=0)	{
?>
	   <br>
	   
     <a href="javascript:if(confirm('Are you sure you want to reverse the last bid for \'<?php print $bid_row->current_bid_price; ?>\'?')==true) location.href='admin.php?page=wp-auctions-manage&amp;wpa_action=reverse&amp;wpa_id=<?php echo $row->id ?>&amp;bid_id=<?php echo $bid_row->id ?>&amp;_wpnonce=<?php echo $nonce ?>'" class="edit"><?php _e('Cancel Last Bid','wpauctions'); ?></a><br/><br/>
<?php
	}
?>			
          </td>
				<td><?php if ( $row->current_price > 0 ) { echo $currencysymbol.$row->current_price; } else { echo _e("No bids",'wpauctions'); }?><?php if ($row->BIN_price>0) print "<br>BIN Price: ".$row->BIN_price ?></td>
				<td style="text-align: center;"><img src="<?php print wpa_resize($row->image_url,150); ?>" width="100" height="100"></td>
				<td>
            <a href="javascript:if(confirm('<?php _e('Are you sure you want to end auction','wpauctions'); ?> \'<?php print addslashes(str_replace ( '"' , "'" , $row->name)); ?>\'?')==true) location.href='admin.php?page=wp-auctions-manage&amp;wpa_action=terminate&amp;wpa_id=<?php echo $row->id ?>&amp;_wpnonce=<?php echo $nonce ?>'" class="edit"><?php _e('End Auction','wpauctions'); ?></a><br/><br/>
				    <a href="admin.php?page=wp-auctions-add&amp;wpa_action=edit&amp;wpa_id=<?php print $row->id ?>&amp;_wpnonce=<?php echo $nonce ?>" class="edit"><?php _e('Edit','wpauctions'); ?></a><br/><br/>
            <a href="javascript:if(confirm('<?php _e('Delete auction','wpauctions'); ?> \'<?php print addslashes(str_replace ( '"' , "'" , $row->name)); ?>\'? (This will erase all details on bids, winners and the auction)')==true) location.href='admin.php?page=wp-auctions-manage&amp;wpa_action=delete&amp;wpa_id=<?php echo $row->id ?>&amp;_wpnonce=<?php echo $nonce; ?>'" class="edit"><?php _e('Delete','wpauctions'); ?></a>
        </td>
			</tr>
			<?php
				if($intAlternate == 1):
					$intAlternate=0;
				else:
					$intAlternate=1;
				endif;
			?>
		<?php } ?>
	<?php else: ?>
		<tr><td colspan="5"><?php _e('No auctions defined','wpauctions'); ?></td></tr>
	<?php endif; ?>
	</table>
	</fieldset>

	<fieldset class="options">
	<legend><?php _e('Closed Auctions','wpauctions'); ?></legend>
	<?php
		$table_name = $wpdb->prefix . "wpa_auctions";
		$strSQL = "SELECT id, date_create, date_end, name, image_url, current_price FROM $table_name WHERE '".current_time('mysql',"1")."' >= date_end ORDER BY date_end";
		$rows = $wpdb->get_results ($strSQL);

	?>
	<table class="widefat" style="margin: 0 0 10px;">
       <thead>
		<tr>
			<th><?php _e('ID','wpauctions'); ?></th>
			<th><?php _e('Name','wpauctions'); ?></th>
			<th><?php _e('Created/Ending','wpauctions'); ?></th>
			<th><?php _e('Bids','wpauctions'); ?></th>
			<th><?php _e('Final Price','wpauctions'); ?></th>
			<th><?php _e('Thumbnail','wpauctions'); ?></th>
			<th><?php _e('Actions','wpauctions'); ?></th>
		</tr>
       </thead>
	<?php if (is_array($rows)): ?>
		<?php foreach ($rows as $row) { 
             $style=" ";
             if($intAlternate==1) $style=$style."alternate "; 
             if(strtotime($row->date_end)<=strtotime("now")) $style=$style."active ";

             ?>
			<tr<?php if($style!=" "): ?> class="<?php echo $style ?>"<?php endif; ?>>
				<td><?php print $row->id; ?></td>
				<td><?php print $row->name; ?> </td>
				<td><b><?php _e('Started:','wpauctions'); ?></b><br> <?php print get_date_from_gmt($row->date_create); ?> <br>
				    <b><?php _e('Ended:','wpauctions'); ?></b><br> <?php print get_date_from_gmt($row->date_end); ?></td>
				<td>
				
<?php
					// prepare result
	$strSQL = "SELECT bidder_name, bidder_email ,date,current_bid_price, bid_type FROM $bid_table_name WHERE auction_id=".$row->id." ORDER BY current_bid_price DESC";
	$bid_rows = $wpdb->get_results ($strSQL);
			
	foreach ($bid_rows as $bid_row) {
	   echo ('<a href="mailto:'.$bid_row->bidder_email.'">');
	   echo ($bid_row->bidder_name);
	   echo ('</a> - '.$currencysymbol.$bid_row->current_bid_price);
	   echo ('['.$bid_row->bid_type.']');	   
	   echo ('<br>');
	}		
			
?>
				</td>
				<td><?php print $currencysymbol.$row->current_price; ?> </td>
				<td style="text-align: center;"><img src="<?php print wpa_resize($row->image_url,125);  ?>" width="100" height="100"></td>
				<td>
				    <a href="admin.php?page=wp-auctions-add&amp;wpa_action=relist&amp;wpa_id=<?php print $row->id ?>&amp;_wpnonce=<?php echo $nonce ?>" class="edit"><?php _e('Relist','wpauctions'); ?></a><br/><br/>
            <a href="javascript:if(confirm('Delete auction \'<?php print addslashes(str_replace ( '"' , "'" , $row->name)); ?>\'? (This will erase all details on bids, winners and the auction)')==true) location.href='admin.php?page=wp-auctions-manage&amp;wpa_action=delete&amp;wpa_id=<?php echo $row->id; ?>&amp;_wpnonce=<?php echo $nonce ?>'" class="edit"><?php _e('Delete','wpauctions'); ?></a>
        </td>
			</tr>
			<?php
				if($intAlternate == 1):
					$intAlternate=0;
				else:
					$intAlternate=1;
				endif;
			?>
		<?php } ?>
	<?php else: ?>
		<tr><td colspan="5"><?php _e('No auctions defined','wpauctions'); ?></td></tr>
	<?php endif; ?>
	</table>
	</fieldset>

</div>

<?php   
}

function wp_auctions_email() {

   // Note: Options for this plugin include a "Title" setting which is only used by the widget
   $options = get_option('wp_auctions_email');
	
   //set initial values if none exist
   if ( !is_array($options) ) {
      $options = array( 'windowsmail'=>'', 'outbid'=>'', 'win'=>'' );
   }

   if ( $_POST['wp_auctions-submit'] ) {

      // security check
      check_admin_referer( 'WPA-nonce');

      $options['windowsmail'] = strip_tags(stripslashes($_POST['wpa-windowsmail']));
      $options['outbid'] = strip_tags(stripslashes($_POST['wpa-outbid']));
      $options['win'] = strip_tags(stripslashes($_POST['wpa-win']));

      update_option('wp_auctions_email', $options);
   }

   $txtWindowsMail = $options['windowsmail'];
   $txtOutBid = htmlspecialchars($options['outbid'], ENT_QUOTES);
   $txtWin = htmlspecialchars($options['win'], ENT_QUOTES);
	
?>

<link href="../wp-content/plugins/wp-auctions/requisites/style.css" rel="stylesheet" type="text/css" />

<div class="wrap wp-auctions">
    
  <form name="form1" method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=wp-auctions-email">
  
  <?php wp_nonce_field('WPA-nonce'); ?>

  <h2 class="settings emailsettings"><em><?php _e('Email Settings','wpauctions') ?></em></h2>

    <table width="100%" cellspacing="2" cellpadding="5" class="widefat" style="margin-top: 1em;"> 
      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title' style="border-bottom: 0;"><?php _e('Mail Server:','wpauctions'); ?></th> 
        <td style="border-bottom: 0;">
         <select id="wpa-windowsmail" name="wpa-windowsmail">
                <option value="" <?php if ($txtWindowsMail=='') echo 'selected'; ?>><?php _e('Standard','wpauctions'); ?></option>
                <option value="Windows" <?php if ($txtWindowsMail !='') echo 'selected'; ?>><?php _e('Implement Windows Fix','wpauctions'); ?></option>
         </select>
        <br />
        <p><?php _e('If you are using the plugin on a Windows Server, you may need to change this setting to implement a change for Windows. <a href="http://www.u-g-h.com/2007/04/27/phpmailer-issue-on-iis/">More info</a>','wpauctions') ?></p></td> 
      </tr> 
    </table>

  <h2 class="settings"><em><?php _e('Custom Message Settings','wpauctions') ?></em></h2>

    <table width="100%" cellspacing="2" cellpadding="5" class="widefat" style="margin-top: 1em;"> 
     <tr valign="top" class="alternate">
     <th scope="row" class='row-title'><?php _e('Message Options:','wpauctions'); ?></th> 
     <td>
     <p><strong>{site_name}</strong> - <?php _e('The name of your auction site','wpauctions'); ?></p>
     <p><strong>{auction_name}</strong> - <?php _e('The name of the auction this message relates to','wpauctions'); ?></p>
     <p><strong>{auction_link}</strong> - <?php _e('Link back to the auction about which the email is being sent','wpauctions'); ?></p>
     <p><strong>{current_price}</strong> - <?php _e('Current price of the auction about which the email is being sent','wpauctions'); ?></p>
     <p><strong>{payment_details}</strong> - <?php _e('Details of how the payment is to be made','wpauctions'); ?></p>
     <p><strong>{contact_email}</strong> - <?php _e('Your contact email address','wpauctions'); ?></p>
     </td>
	</tr>
      <tr valign="top" class="alternate"> 
        <th scope="row" class='row-title'><?php _e('Auction outbid notice:','wpauctions') ?></th> 
        <td>
        
        <?php

		$content = $txtOutBid;
		$id = 'wpa-outbid';
		$settings = array(
			'quicktags' => array(
				'buttons' => 'em,strong,link',
			),
			'quicktags' => true,
			'media_buttons' => false,
			'tinymce' => true,
			'height' => 100
		);

		wp_editor($content, $id, $settings);
		
		?>        
 
        <br />
        <p><?php _e('If you want a custom message to use when a bidder is outbid, please enter it here. You can use the keywords:<br><strong>{site_name}, {auction_name}, {auction_link}, {current_price}','wpauctions') ?></p></td> 
      </tr> 
      <tr valign="top"> 
        <th scope="row" class='row-title' style="border-bottom: 0;"><?php _e('Auction win notice:','wpauctions') ?></th> 
        <td style="border-bottom: 0;">
        
        <?php

		$content = $txtWin;
		$id = 'wpa-win';
		$settings = array(
			'quicktags' => array(
				'buttons' => 'em,strong,link',
			),
			'quicktags' => true,
			'media_buttons' => false,
			'tinymce' => true,
			'height' => 100
		);

		wp_editor($content, $id, $settings);
		
		?>        
 
        <br />
        <p><?php _e('If you want a custom message to use when a bidder wins an auction, please enter it here. You can use the keywords:<br><strong>{site_name}, {auction_name}, {auction_link}, {current_price} {payment_details} {contact_email}','wpauctions') ?></p></td> 
      </tr> 
    </table>


	<input type="hidden" id="wp_auctions-submit" name="wp_auctions-submit" value="1" />

    <p>
      <input type="submit" name="Submit" class="button add-auction" value="<?php _e('Update Options','wpauctions'); ?> &raquo;" />
    </p>
  </form> 
</div>

<?php


}


// style header - Load CSS and LightBox Javascript

function wp_auctions_header() {

   $options = get_option('wp_auctions');
   $style = $options['style'];

   echo "\n" . '<!-- wp_auction start -->' . "\n";
   echo '<link type="text/css" rel="stylesheet" href="' . get_bloginfo('wpurl') . '/wp-includes/js/thickbox/thickbox.css" />' . "\n\n";
   echo '<link type="text/css" rel="stylesheet" href="' . WPA_PLUGIN_STYLE . '/'.$style.'/color.css" />' . "\n\n";
   if (function_exists('wp_enqueue_script')) {
      wp_enqueue_script('jquery');
      wp_enqueue_script('thickbox');
      wp_enqueue_script('wp_auction_AJAX', WPA_PLUGIN_URL . '/wp_auctionsjs.php' );
      wp_enqueue_script('jquery-ui-tabs');
	  wp_enqueue_style('jquery_ui_css', 'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.11.4/jquery-ui.css' );

// this loads scripts in the header instead of the footer, apparently it loads other scripts too -- wp_print_scripts();
      
?>      
  
<?php      
      
   } else {
      echo '<!-- WordPress version too low to run WP Auctions -->' . "\n";
   }
      
   echo '<!-- wp_auction end -->' . "\n\n";

}

// add shortcode support to allow user to insert auctions in posts or pages
add_shortcode('wpauction', 'insertAuction');

function insertAuction ( $attr) {
   extract(shortcode_atts(array(
      'id' => 1
   ), $attr));

   $content = dopost_wp_auctions($id);
   
   return $content;
}

function insertAuctionSelector() {

   global $wpdb;
	 $table_name = $wpdb->prefix . "wpa_auctions";
	 $strSQL = "SELECT id, name, image_url FROM $table_name WHERE '".current_time('mysql',"1")."' < date_end ORDER BY date_end DESC";
	 $rows = $wpdb->get_results ($strSQL);

?>
   <table class="form-table">
      <tr valign="top">
         <th scope="row"><label for="WPA_Admin_id"><?php _e('Select an auction','wpauctions'); ?></label></th>
         <td>
            
	<?php if (is_array($rows)): ?>
        <select name="WPA_Admin[id]" id="WPA_Admin_id" style="width:95%;">
		       <?php foreach ($rows as $row) { 
		          echo '<option value="'.$row->id.'">'.$row->name.'</option>';
           } ?>
         </select> 
         <br>(<?php _e('You should only have a single auction on each page or post','wpauctions'); ?>)    
  <?php else:
          echo _e('Please create some auctions first','wpauctions'); 
         endif; 
  ?>          
            
         </td>
      </tr>
   </table>
   <p style="text-align: right;">
      <input type="button" class="button" onclick="return WPA_Setup.sendToEditor(this.form);" value="<?php _e('Insert Auction','wpauctions'); ?>" />
   </p>
<?php
}

function wpa_adminWPHead() {
   if ($GLOBALS['editing']) {
      wp_enqueue_script('WPA_Admin', WPA_PLUGIN_URL . '/wp_aAdminjs.js', array('jquery'), '1.0.0' );
   }
}

function wpa_admin_scripts() {

	wp_enqueue_script( 'jquery-ui-datetimepicker', WPA_PLUGIN_URL . '/js/jquery-ui-timepicker-addon.js', array('jquery-ui-datepicker','jquery-ui-slider') , 0.1, true );
   wp_enqueue_media();
   wp_enqueue_script( 'custom-header' );
   
}

function wpa_admin_styles() {

	wp_enqueue_style('jquery-style', 'http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.1/themes/smoothness/jquery-ui.css');
	wp_enqueue_style( 'jquery-ui-datetimepicker', WPA_PLUGIN_URL . '/js/timepicker.custom.css' );

}

if (isset($_GET['page']) && $_GET['page'] == 'wp-auctions-add') {
   add_action('admin_print_scripts', 'wpa_admin_scripts');
   add_action('admin_print_styles', 'wpa_admin_styles');
}


function wp_auctions_adminmenu(){

   // add new top level menu page
   add_menu_page ('WP Auctions', 'WP Auctions' , 'edit_pages' , WPA_PLUGIN_NAME , 'wp_auctions_welcome', WPA_PLUGIN_REQUISITES."/wpa.png" );

   // add submenus
   add_submenu_page (WPA_PLUGIN_NAME, __('Manage','wpauctions'), __('Manage','wpauctions'), 'edit_pages' , 'wp-auctions-manage', 'wp_auctions_manage' );
   add_submenu_page (WPA_PLUGIN_NAME, __('Add','wpauctions'), __('Add','wpauctions'), 'edit_pages' , 'wp-auctions-add', 'wp_auctions_add' );
   add_submenu_page (WPA_PLUGIN_NAME, __('Email Settings','wpauctions'), __('Email Settings','wpauctions'), 'edit_pages' , 'wp-auctions-email', 'wp_auctions_email' );

   add_meta_box('WPA_Admin', __('Insert Auction','wpauctions'), 'insertAuctionSelector', 'post', 'normal', 'high');
   add_meta_box('WPA_Admin', __('Insert Auction','wpauctions'), 'insertAuctionSelector', 'page', 'normal', 'high');   

}

function wpa_init()
{

	// define thumbnail sizes
	add_image_size( 'WPA_thumbnail', 50, 50, true );
	add_image_size( 'WPA_widget', 125, 125, true );
	add_image_size( 'WPA_page', 100, 100, true );
	add_image_size( 'WPA_popup', 250, 250, true );

}


add_filter('admin_print_scripts', 'wpa_adminWPHead');

add_action('wp_head', 'wp_auctions_header');
add_action('widgets_init', 'widget_wp_auctions_init');
add_action('admin_menu','wp_auctions_adminmenu',1);
add_action('activate_'.plugin_basename(__FILE__), 'wp_auctions_install');
add_action('deactivate_'.plugin_basename(__FILE__), 'wp_auctions_uninstall');
add_action('wpa_daily_check', 'close_expired_auctions');
add_action('init', 'wpa_init', 0 );

?>