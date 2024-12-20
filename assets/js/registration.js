(function($) {
    'use strict';

    class WebinarGeekRegistration {
        constructor() {
            this.form = $('#webinar-registration-form');
            this.messageContainer = $('.webinargeek-messages');
            this.submitButton = this.form.find('button[type="submit"]');
            this.initializeForm();
        }

        initializeForm() {
            this.form.on('submit', (e) => this.handleSubmit(e));
        }

        async handleSubmit(e) {
            e.preventDefault();
            
            // Reset messages
            this.messageContainer.hide().empty();
            
            // Disable submit button
            this.submitButton
                .prop('disabled', true)
                .html('<span class="spinner"></span> Bezig met registreren...');

            try {
                const formData = new FormData(e.target);
                formData.append('action', 'register_webinar');
                formData.append('nonce', webinargeekAjax.nonce);

                const response = await $.ajax({
                    url: webinargeekAjax.ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false
                });

                if (response.success) {
                    this.showMessage(webinargeekAjax.messages.success, 'success');
                    
                    // Check voor redirect URL
                    const redirectUrl = this.form.find('input[name="success_redirect"]').val();
                    if (redirectUrl) {
                        window.location.href = redirectUrl;
                        return;
                    }

                    // Reset form als er geen redirect is
                    this.form[0].reset();
                } else {
                    throw new Error(response.data.message || webinargeekAjax.messages.error);
                }
            } catch (error) {
                this.showMessage(error.message, 'error');
            } finally {
                // Reset submit button
                this.submitButton
                    .prop('disabled', false)
                    .text('Registreren');
            }
        }

        showMessage(message, type = 'info') {
            this.messageContainer
                .removeClass('webinargeek-success webinargeek-error webinargeek-info')
                .addClass(`webinargeek-${type}`)
                .html(message)
                .slideDown();

            // Scroll naar bericht
            $('html, body').animate({
                scrollTop: this.messageContainer.offset().top - 100
            }, 500);
        }
    }

    // Initialize on document ready
    $(document).ready(() => {
        new WebinarGeekRegistration();
    });

})(jQuery);