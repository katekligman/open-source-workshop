<?php //prepare details here  ?>

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
      send_to_editor(this.generateShortCode());
      return false;
   }
}

var WPA_Setup = new WPA_Admin();