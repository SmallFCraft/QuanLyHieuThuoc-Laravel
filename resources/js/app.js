import './bootstrap';
import './cart-add';
import './favorites';
import './search-autocomplete';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Import configurations
import * as SweetAlert from './sweetalert-config';
import * as DataTables from './datatables-config';
import * as Charts from './chart-config';

// Make available globally
window.SweetAlert = SweetAlert;
window.DataTables = DataTables;
window.Charts = Charts;

// Import Bootstrap JS and expose it for legacy inline Blade handlers.
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;
