(function ($, Drupal, drupalSettings) {

  /**
   * @namespace
   */
  Drupal.elasticsearch = {};

  Drupal.behaviors.elasticObjectPropertySelect = {
    attach: function attach(context, settings) {
      var $buttons = $('[data-drupal-selector="edit-options-property-select-button"], [data-drupal-selector="edit-property-group-select-button"]').once('elastic-object-property-select');
      var length = $buttons.length;
      var i = void 0;
      for (i = 0; i < length; i++) {
        new Drupal.elasticsearch.onPropertySelectChange($buttons[i]);
      }
    }
  };

  Drupal.elasticsearch.onPropertySelectChange = function (button) {
    this.$button = $(button);
    this.$parent = this.$button.parent('div.views-property, div.views-grouped');
    this.$select = this.$parent.find('select');

    this.$button.hide();
    this.$parent.find('.property-description, .grouped-description').hide();

    this.$select.on('change', $.proxy(this, 'clickHandler'));
  };

  Drupal.elasticsearch.onPropertySelectChange.prototype.clickHandler = function (e) {
    this.$button.trigger('click').trigger('submit');
  };

})(jQuery, Drupal, drupalSettings);
