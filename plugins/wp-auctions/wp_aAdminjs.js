

var WPA_Admin = function() {}

WPA_Admin.prototype = {
   options             : {},
   generateShortCode   : function() {
      var attrs = '';
      jQuery.each(this['options'], function(name,value) {
         if (value != '') {
            attrs += ' ' + name + '="' + value + '"';
         }
      });
      return '[wpauction' + attrs + ' /]';
   },
   sendToEditor        : function(f) {
      var collection = jQuery(f).find("input[id^=WPA]:not(input:checkbox),input[id^=WPA]:checkbox:checked,select[id^=WPA]");
      var $this = this;
      collection.each(function () {
         var name = this.name.substring(10, this.name.length - 1 );
         $this['options'][name] = this.value;
      });
      
      var shortCode = this.generateShortCode();
      
      // check which editor is active and which method of insertion to use
      if (document.body.classList.contains( 'block-editor-page' )) {
          WPA_send_to_Gutenberg(shortCode);
      } else {
          send_to_editor(shortCode);
      }

      return false;
   }
}

var WPA_Setup = new WPA_Admin();



function WPA_send_to_Gutenberg(shortCode) {
    
    // create a new shortcode block
    var shortBlock = wp.blocks.createBlock('core/shortcode');
    shortBlock.attributes.text = shortCode;
    
    // find the index at we need to need to insert the block
    var insertionPoint = wp.data.select('core/editor').getBlockInsertionPoint();
    var insertionIndex = insertionPoint.index;
    
    // insert the new block
    wp.data.dispatch('core/editor').insertBlock(shortBlock, insertionIndex);
    
}