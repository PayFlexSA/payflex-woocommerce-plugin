( function () {
    var el = window.wp.element.createElement;

    function PayflexNotice( props ) {
        var data = props.extensions && props.extensions.payflex;

        if ( ! data || data.eligible || ! data.message ) {
            return null;
        }

        // The same banner markup the Cart and Checkout blocks use for their own
        // notices, so the message is styled instead of rendering as bare text.
        return el(
            'div',
            { className: 'wc-block-components-notice-banner is-info payflex-eligibility-notice' },
            el(
                'div',
                { className: 'wc-block-components-notice-banner__content' },
                data.message
            )
        );
    }

    function render() {
        return el(
            window.wc.blocksCheckout.ExperimentalOrderMeta,
            null,
            el( PayflexNotice, null )
        );
    }

    // A plugin only fills the slots of the scope it is registered for, so the
    // Cart and Checkout blocks each need their own registration.
    window.wp.plugins.registerPlugin( 'payflex-eligibility-cart', {
        render: render,
        scope: 'woocommerce-cart'
    } );

    window.wp.plugins.registerPlugin( 'payflex-eligibility-checkout', {
        render: render,
        scope: 'woocommerce-checkout'
    } );
} )();
