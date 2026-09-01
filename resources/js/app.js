import './cart-add';
import './catalog-async';
import './catalog-filter';
import './favorites';
import './header-scroll';
import './profile';
import './search-autocomplete';
import './search-placeholder';
import './voice-search';
import './triage';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

// Storefront call sites use a bare `Swal`, so the bundled copy has to be the global
// one. Previously this was a floating `sweetalert2@11` CDN tag in layouts/app.blade.php
// while the bundled copy shipped unreachable.
import Swal from 'sweetalert2';

window.Swal = Swal;

// Import Bootstrap JS and expose it for legacy inline Blade handlers.
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;
