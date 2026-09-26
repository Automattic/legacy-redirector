import { createRoot } from '@wordpress/element';
import RedirectForm from './redirect-form';
import './app.css';

const settings = window.legacyRedirectorDataForm;

createRoot( document.getElementById( 'legacy-redirector-dataform' ) ).render(
	<RedirectForm settings={ settings } />
);
