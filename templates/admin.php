 <div class="orkesta-admin-container">
            <div class="orkesta-header">
                <img class="orkesta-img" alt="OrkestaPay" height="40" src="<?php echo plugins_url('./../assets/images/orkestapay.svg', __FILE__); ?>"/>
                <div class="orkesta-copy">                
                    <p><?php echo __('Unlocking Payment Ecosystem', 'orkestapay'); ?></p>
                </div>
            </div>
            <div class="orkesta-form-container">                
                <p class="instructions"><?php echo __('For more information about plugin configuration ', 'orkestapay'); ?><a href="https://orkestapay.com" target="_blank"><?php echo __(
    'click here',
    'orkestapay'
); ?>.</a></p>
                <hr>
                <table class="form-table">
                    <input autocomplete="false" aria-autocomplete="none" name="hidden" type="text" style="display: none;" />
                    <?php $this->generate_settings_html(); ?>                
                </table> 
            </div>
        </div>