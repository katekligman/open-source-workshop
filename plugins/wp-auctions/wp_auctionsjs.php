<?php 

// cater for stand-alone calls
if (!function_exists('get_option'))
	require_once('../../../wp-config.php');

$wpa_version = "3.6";

$options = get_option('wp_auctions');
$customcontact = $options['customcontact'];

// set up security
$nonce= wp_create_nonce('WPA-nonce');

// Consts
define('PLUGIN_EXTERNAL_PATH', '/wp-content/plugins/wp-auctions/');
define('PLUGIN_STYLE_PATH', 'wp-content/plugins/wp-auctions/styles/');
define('PLUGIN_NAME', 'wp_auctions.php');
define('PLUGIN_PATH', 'wp-auctions/wp_auctions.php');

define('BID_WIN', 'Congratulations, you are the highest bidder on this item.');
define('BID_LOSE', "I'm sorry, but a preceeding bidder has outbid you.");
define('BIN_WIN', "Thanks for buying! Payment instructions have been emailed.");

header("Content-Type:text/javascript"); ?>
// Popup front-end code

// This code needs to be refactored to consolidate all the similar routines

// AJAX Functions
// Functions are all seperate so we could do different funky stuff with each

var ajax_auction_loading = false;
var ajax_bid_loading = false;
var ajax_other_loading = false;

function ajax_auctions_loading(on) {
   if (on) {
      ajax_auction_loading = true;
      // do funky stuff here
   } else {
      // clear funky stuff here
      ajax_auction_loading = false;
   }
}

function ajax_bids_loading(on) {
   if (on) {
      ajax_bid_loading = true;
      // do funky stuff here
   } else {
      // clear funky stuff here
      ajax_bid_loading = false;
   }
}

//Ajax.Responders.register({
//    onCreate: function(){ Element.show('spinner')},
//    onComplete: function(){Element.hide('spinner')}
//});

function ajax_others_loading(on) {
   if (on) {
      ajax_other_loading = true;
      // do funky stuff here
   } else {
      // clear funky stuff here
      ajax_other_loading = false;
   }
}

function process_bin(BIN_price) {

   // make up replacement text and hidden field

   new_string = BIN_price + "(B.I.N. Price)";
   new_string = new_string + "<input name='BidAmount' type='hidden' id='BidAmount' value='" + BIN_price + "'/>"
   new_string = new_string + "<input name='BINAmount' type='hidden' id='BINAmount' value='" + BIN_price + "'/>"
   jQuery('#wp-bin-manip').html(new_string);
}

function swap_image(url) {
  jQuery('#wp-image-p').fadeOut("slow",function() { 
     jQuery('#wp-image-p').html('<img src="' + url +'" alt="Loading image ..." width="250" height="250" />');
  } );  
  jQuery('#wp-image-p').fadeIn();
}


function ajax_auction_request() {

   // retreive form data
   var auction_id = jQuery("input#formauctionid").val(); 
   var currencysymbol = jQuery("input#currencysymbol").val();

   if (ajax_auction_loading) return false;
   
   ajax_auctions_loading ( true );
   
   // new jQuery AJAX routine
   jQuery.ajax ({
      cache: false,
      type: "POST",
      url: '<?=get_settings('siteurl').PLUGIN_EXTERNAL_PATH.PLUGIN_NAME?>?queryauction',
      data : {
         auction_ID : auction_id,
         _ajax_nonce: '<?php echo $nonce ?>'
      },
      error: function(request,textStatus,errorThrown) {
	    alert((request.status!=406? ' WP_Auction Error '+request.status+' : '+request.statusText+'\n' : '')+request.responseText);
      },
   success: function(request, status) {
	    ajax_auctions_loading(false);   
	    if (status!="success") alert (status);  //"return"
	    	    
	    // update auction on screen
	    auction_details = request.split('|');

	    // process BIN if there is one (note: only if auction isn't closed)
      extraBIN = "";
	    BIN_price = auction_details[11];
      if (BIN_price != 0 && auction_details[8] != 0) {
         extraBIN = "<a href='Javascript:process_bin(" + BIN_price + ");' class='wpa-bin-price'>B.I.N. price: " + currencysymbol + BIN_price + "</a>";
      }

      // process extra images if there are any
      extraimages = '';
      thumbnails = '';
      thisimage = 1;
      for(var i=0;i<3;i++) {
         if (auction_details[12+i] != '') { 
            if (extraimages != '') {extraimages = extraimages + ", "; }
         
            swapurl = 'Javascript:swap_image("' + auction_details[12+i] + '")';
            extraimages = extraimages + "<a href='" + swapurl + "'>#" + thisimage++ + "</a>" 
         }
      }
      
      // if we DO have extra images, let's append the main image to the end of the list
      if (extraimages != '' && auction_details[7] != "") {
         swapurl = 'Javascript:swap_image("' + auction_details[7] + '")';
         extraimages = "<strong>More Images:</strong> " + extraimages + ", <a href='" + swapurl + "'>#" + thisimage + "</a>"       
         
         // now let's prep the thumbnails
         if (auction_details[18] != '') {
            thumbs = auction_details[18].split('^');
            for ( i = 0; i < thumbs.length; i++) {
               if (thumbs[i].length > 0) {
                  if ( i < thumbs.length - 1) {
                     swapurl = 'Javascript:swap_image("' + auction_details[12+i-1] + '")';
                  } else {
                     swapurl = 'Javascript:swap_image("' + auction_details[7] + '")'; // special treatment for last one
                  }
                  thumbnails = thumbnails + "<a href='" + swapurl + "'><img src='" + thumbs[i] + "' alt='Auction Images'></a>";
                }
                
             }
             //thumbnails = '<p><img src="<?=get_settings('siteurl').PLUGIN_EXTERNAL_PATH?>/requisites/more-images.png" alt="More Images" /></p>' + thumbnails;   
             thumbnails = '<p><strong><?php _e('More Images','wpauctions'); ?>:</strong></p>' + thumbnails;   
         }
         
      }

      // reset value field to form (in case previous BIN messed with this)
      jQuery('#wp-bin-manip').html('<input name="BidAmount" type="text" class="formbid" id="BidAmount" value="" maxlength="8" align="right"/><input name="BINAmount" type="hidden" id="BINAmount" value="0"/>');

      // deal with Fixed Price BINs
      if (auction_details[6] == 0) {
         process_bin(BIN_price);
         jQuery('#wp_startb').html("<strong>Starting Bid:</strong> B.I.N. price below");
         jQuery('#wp-extrainfo').html("");
         buttoncaption = "Buy Now";
      } else {
         jQuery('#wp_startb').html("<strong>Starting Bid:</strong> " + currencysymbol+auction_details[6]);
         jQuery('#wp-extrainfo').html('<font size="-2">Bid ' + currencysymbol + auction_details[15] + ' or higher</font>');
         buttoncaption = "Bid Now";
      }
	    
      jQuery('#wp-description-p').html(auction_details[2]);
      //jQuery('#wp-otherimages-p').html(thumbnails);
	    jQuery('#tc-heading-p').html(auction_details[1]);
	    jQuery('#wp_price').html("Current Bid: " + currencysymbol + auction_details[3]);
	    	    
	    if (auction_details[7] == "") { auction_details[7]='<?=get_settings('siteurl').PLUGIN_EXTERNAL_PATH?>/requisites/wp-popup-def.gif'   }

      jQuery('#wp-image-p').fadeOut("slow",function() { 
         jQuery('#wp-image-p').html('<img src="'+auction_details[7]+'" alt=" <?php _e('Loading image','wpauctions'); ?> ..." width="250" height="250" />');
      } );  
      jQuery('#wp-image-p').fadeIn();

      // Check if auction is still open      
      if (auction_details[8] == 0) {
         // auction is closed
         jQuery('#wp_endd').html("Auction Ended");
         jQuery("#BidAmount").attr("disabled",true);
         jQuery('#wp-bidnow-p').html('');
         jQuery('#wp_winningb').html('<strong><?php _e('Winning Bid','wpauctions'); ?>:</strong> ' + currencysymbol + auction_details[10] + ' <?php _e('by','wpauctions'); ?> ' + auction_details[9]);
      } else {
         // auction is open
         jQuery('#wp_endd').html("<strong><?php _e('Ending Date','wpauctions'); ?>:</strong> "+auction_details[5]);
         jQuery("#BidAmount").attr("disabled",false);
         jQuery('#bidnow').html('<a href="#" onclick="ajax_submit_bid();">' + buttoncaption + '</a>');
         if (extraimages + extraBIN == '') {
            jQuery('#wp_winningb').html('<strong><?php _e('Winning Bid','wpauctions'); ?>:</strong> <?php _e('Bid to win','wpauctions'); ?>');
         } else {
            //jQuery('#wp_winningb').html(extraBIN + "  " + extraimages);
            jQuery('#wp_winningb').html(extraBIN + "  " + thumbnails);
         }
         
         // append extra shipping information
         shipping = "<strong>Shipping:</strong> " + currencysymbol + auction_details[16];
         jQuery('#wp_shipping').html(shipping);

         if (auction_details[17] != "") { 
            willshipto = "<strong>Will Ship to:</strong> "+auction_details[17]; 
         } else {
            willshipto = ""; // optional
         }
         jQuery('#wp_willshipto').html(willshipto);


      }
      
      }
   });
	 
     // fire off call to update bids
     ajax_bids_request(auction_id);

     // fire off call to update other auctions
     ajax_other_request(auction_id);

	 return false;
}

function ajax_bids_request(auction_id) {

   var currencysymbol = jQuery("input#currencysymbol").val();  
   var customcontact = "<?php echo $customcontact ?>";
   
   if (ajax_bid_loading) return false;
   ajax_bids_loading ( true );

   // new jQuery AJAX routine
   jQuery.ajax ({
      cache: false,
      type: "POST",
      url: '<?=get_settings('siteurl').PLUGIN_EXTERNAL_PATH.PLUGIN_NAME?>?querybids',
      data : {
         auction_ID : auction_id,
         _ajax_nonce: '<?php echo $nonce ?>'
      },
      error: function(request,textStatus,errorThrown) {
	    alert((request.status!=406? ' WP_Auction Error '+request.status+' : '+request.statusText+'\n' : '')+request.responseText);
      },
   success: function(request, status) {

	    ajax_bids_loading(false);   

	    if (status!="success") alert (status);  //"return"
	    
	    // update bids on screen
        if (request == '') {
           var bid_output = '<?php _e('No bids found','wpauctions'); ?>';
        } else {
           bids_details = request.split('|');

           var bid_output = '<ol class="wp-detailsbidders-p">';
           var lines = (bids_details.length/5)-1;
	       for(var i=0;i<lines;i++) {
              bid_output = bid_output + '<li>';
              if (bids_details[i*5+2]=="" || customcontact != "") {
                 bid_output = bid_output + bids_details[i*5+1];
              } else {
                 bid_output = bid_output + '<a href="' + bids_details[i*5+2] + '" target="_blank">' + bids_details[i*5+1] + '</a>';
              }
              bid_output = bid_output + ' bid ' + currencysymbol + bids_details[i*5+4] + ' on ' + bids_details[i*5+3];
              if (bids_details[i*5+5] == "auto") {
                 bid_output = bid_output + ' [auto]';
              }
              bid_output = bid_output + '</li>';
           }
	       bid_output = bid_output + '</ol>';
        }   

        jQuery('#wp-bids-p').slideUp("slow",function() { 
           jQuery('#wp-bids-p').html(bid_output); 
        });
        jQuery('#wp-bids-p').slideDown();

	 }})
	 
	 return false;
}


function ajax_other_request(auction_id) {

   if (ajax_other_loading) return false;
   ajax_others_loading ( true );
   
   // new jQuery AJAX routine
   jQuery.ajax ({
      cache: false,
      type: "POST",
      url: '<?=get_settings('siteurl').PLUGIN_EXTERNAL_PATH.PLUGIN_NAME?>?queryother',
      data : {
         auction_ID : auction_id,
         _ajax_nonce: '<?php echo $nonce ?>'
      },
      error: function(request,textStatus,errorThrown) {
   	    alert((request.status!=406? ' WP_Auction Error '+request.status+' : '+request.statusText+'\n' : '')+request.responseText);
      },
   success: function(request, status) {

	    ajax_others_loading(false);   

	    if (status!="success") alert (status);  //"return"
	    
	    // update others on screen - returns multiples of 3, max 12

      if (request == "") {
         jQuery('#wp-other-p').html(''); 
      } else {
      
        other_details = request.split('|');
        
          odetdiv = '';
          for(var i=0;i<4;i++) {
             if (other_details[i*6+3] != undefined) {
                if (other_details[i*6+3] == '') {
                   odetdiv = odetdiv + '<li><a href="#" title="' + other_details[i*6+2] + '">';  
                   odetdiv = odetdiv + '<img src="<?=get_settings('siteurl').PLUGIN_EXTERNAL_PATH?>/requisites/wp-thumb-def.gif" border="0" alt="' + other_details[i*6+2] + '" width="50" height="50" onclick="document.getElementById(\'formauctionid\').value=' + other_details[i*6+1] + ';ajax_auction_request()"/>'; 
                   odetdiv = odetdiv + '</a><p>'+other_details[i*6+2]+'</p><p><?php _e('Current Bid','wpauctions'); ?>: '+other_details[i*6+5]+'</p></li>';  
                }
                else {
                   odetdiv = odetdiv + '<li><a href="#" title="' + other_details[i*6+2] + '">';  
                   odetdiv = odetdiv + '<img src="' + other_details[i*6+3] + '" border="0" alt="' + other_details[i*6+2] + '" width="50" height="50" onclick="document.getElementById(\'formauctionid\').value=' + other_details[i*6+1] + ';ajax_auction_request()"/>';  
                   odetdiv = odetdiv + '</a><p class="wpa-other-title"><a href="#" title="' + other_details[i*6+2] + '" onclick="document.getElementById(\'formauctionid\').value=' + other_details[i*6+1] + ';ajax_auction_request()">'+other_details[i*6+2]+'</a></p><p>Current Bid: '+other_details[i*6+6]+'</p></li>';  
                }
             } else {
                // Should be nothing here .. let's see how it goes ..
             }
          }
   
          jQuery('#wp-other-p').html('<ul id="wp-othercontainer-p">' + odetdiv + '</ul>');
     }

	 }})
	 
	 return false;
}


function ajax_submit_bid() {
 
   // retreive form values
   var auction_id = jQuery("input#formauctionid").val(); 
   var bidder_name = jQuery("input#Name").val();
   var bidder_email = jQuery("input#Email").val();
   var bidder_url = jQuery("input#URL").val();
   var max_bid = jQuery("input#BidAmount").val();
   var BIN_amount = jQuery("input#BINAmount").val();

   // new jQuery AJAX routine
   jQuery.ajax ({
      cache: false,
      type: "POST",
      url: '<?=get_settings('siteurl').PLUGIN_EXTERNAL_PATH.PLUGIN_NAME?>?postauction',
      data : {
         auction_id   : auction_id,
         bidder_name  : bidder_name,
         bidder_email : bidder_email,
         bidder_url   : bidder_url,
         max_bid      : max_bid,
         BIN_amount   : BIN_amount,
         _ajax_nonce: '<?php echo $nonce ?>'
      },
      error: function(request,textStatus,errorThrown) {
   	    alert((request.status!=406? ' WP_Auction Error '+request.status+' : '+request.statusText+'\n' : '')+request.responseText);
      },
   success: function(request, status) {

	    if (status!="success") alert (status);  //"return"

      // trim it (17/5/20 - no idea why i'm doing this
      request = jQuery.trim(request);
   
      // give user their response
      alert ( request );

       // fire off call to update auction details
       ajax_auction_request(auction_id);
	 }})
	 
	 return false;
}

function get_rss() {
   window.location = "<?=get_settings('siteurl').PLUGIN_EXTERNAL_PATH.PLUGIN_NAME?>?rss";
}