const settings = window.wc.wcSettings.getSetting( 'whop_data', {} );
const label = window.wp.htmlEntities.decodeEntities( settings.title ) || 'Whop';
const Content = () => {
    return window.wp.htmlEntities.decodeEntities( settings.description || 'Pay securely with Whop.' );
};

const WhopPaymentMethod = {
    name: "whop",
    label: window.wp.element.createElement('span', null, label),
    content: window.wp.element.createElement(Content, null),
    edit: window.wp.element.createElement(Content, null),
    canMakePayment: () => true,
    ariaLabel: label,
    supports: {
        features: settings.supports,
    },
};

window.wc.wcBlocksRegistry.registerPaymentMethod(WhopPaymentMethod);
