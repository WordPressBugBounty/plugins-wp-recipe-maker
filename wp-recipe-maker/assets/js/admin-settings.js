import { createRoot } from 'react-dom/client';
import React from 'react';
import App from './admin-settings/App';
import KitchenPage from './admin-settings/KitchenPage';

const container = document.getElementById( 'wprm-settings' );
if (container) {
	const root = createRoot(container);
	root.render(<App/>);
}

const kitchenContainer = document.getElementById( 'wprm-kitchen' );
if (kitchenContainer) {
	const root = createRoot(kitchenContainer);
	root.render(<KitchenPage/>);
}
