/**
 * Sync Guard - Front-End Controller for Inventory Updates
 *
 * Implements:
 * 1. Safe DOM updates strictly via textContent and createElement (zero raw HTML injection)
 * 2. Robust idempotency key lifecycle (preserved on error/retry, renewed only on 200/201 success)
 * 3. Date presets and live date span summary using database-provided JST clock
 * 4. Japanese IME full-width digit normalization (０-９, ￥) to half-width on blur
 * 5. Price comparison against room base price (+/- percentage)
 * 6. Stepper controls and room-capacity validation
 * 7. Accessible error summary and inline field errors with focus management
 * 8. Live update summary card
 * 9. Independent recent updates polling chain with exponential backoff and tab-visibility awareness
 */

$(function () {
    'use strict';

    // ========================================================================
    // 1. Theme & Wall Mode Managers
    // ========================================================================
    function initTheme() {
        var $themeToggle = $('#theme-toggle');
        var $themeToggleIcon = $('#theme-toggle-icon');
        if (!$themeToggle.length) return;
        if (window.__syncguard_theme_initialized) return;
        window.__syncguard_theme_initialized = true;

        var storedTheme = null;
        try {
            storedTheme = localStorage.getItem('syncguard_theme');
        } catch (e) {}
        var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        var activeTheme = storedTheme || (prefersLight ? 'light' : 'dark');
        applyTheme(activeTheme);

        $themeToggle.on('click', function () {
            var current = $('html').attr('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try {
                localStorage.setItem('syncguard_theme', next);
            } catch (e) {}
        });

        function applyTheme(theme) {
            $('html').attr('data-theme', theme);
            var isLight = theme === 'light';
            $themeToggle.attr('aria-pressed', isLight ? 'true' : 'false');
            $themeToggleIcon.text(isLight ? '☀️' : '🌙');
        }
    }

    function initWallMode() {
        var $wallModeToggle = $('#wall-mode-toggle');
        if (!$wallModeToggle.length) return;
        if (window.__syncguard_wall_initialized) return;
        window.__syncguard_wall_initialized = true;

        var isWall = false;
        try {
            isWall = localStorage.getItem('syncguard_wall_mode') === 'true';
        } catch (e) {}
        applyWallMode(isWall);

        $wallModeToggle.on('click', function () {
            var active = !$('body').hasClass('wall-mode');
            applyWallMode(active);
            try {
                localStorage.setItem('syncguard_wall_mode', String(active));
            } catch (e) {}
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('body').hasClass('wall-mode')) {
                applyWallMode(false);
                try {
                    localStorage.setItem('syncguard_wall_mode', 'false');
                } catch (err) {}
            }
        });

        function applyWallMode(active) {
            $('body').toggleClass('wall-mode', active);
            $wallModeToggle.attr('aria-pressed', active ? 'true' : 'false');
        }
    }

    // DOM Element References
    const form = document.getElementById('inventory_form');

    const roomIdSelect = document.getElementById('room_id');
    const dateFromInput = document.getElementById('date_from');
    const dateToInput = document.getElementById('date_to');
    const availableUnitsInput = document.getElementById('available_units');
    const priceInput = document.getElementById('price');
    const idempotencyInput = document.getElementById('idempotency_key');
    const submitBtn = document.getElementById('submit_btn');
    const submitBtnText = document.getElementById('submit_btn_text');
    const submitSpinner = document.getElementById('submit_spinner');
    const startOverBtn = document.getElementById('btn-start-over');

    const roomHelperText = document.getElementById('room_helper_text');
    const dateLiveSummary = document.getElementById('date_live_summary');
    const unitsMinusBtn = document.getElementById('btn-units-minus');
    const unitsPlusBtn = document.getElementById('btn-units-plus');
    const soldOutBtn = document.getElementById('btn-sold-out');
    const allRoomsBtn = document.getElementById('btn-all-rooms');
    const priceComparison = document.getElementById('price_comparison');
    const formLiveSummary = document.getElementById('form_live_summary');
    const errorSummary = document.getElementById('form-error-summary');
    const errorSummaryList = document.getElementById('error-summary-list');
    const resultCard = document.getElementById('result-card');
    const resultIcon = document.getElementById('result-icon');
    const resultMessage = document.getElementById('result-message');
    const resultLinks = document.getElementById('result-links');
    const liveFeedbackRegion = document.getElementById('live-feedback-region');
    const recentTbody = document.getElementById('recent_updates_tbody');
    const recentStaleNotice = document.getElementById('recent-stale-notice');

    // Server-provided reference JST today (YYYY-MM-DD)
    const serverTodayJst = (form && form.getAttribute('data-today-jst')) || new Date().toISOString().slice(0, 10);

    let isSubmitting = false;
    let recentPollTimeoutId = null;
    let recentPollCounter = 0;
    let recentBackoffIdx = 0;
    const recentBackoffDelays = [10000, 20000, 30000]; // 10s, 20s, 30s

    /**
     * Generate an RFC 4122 Version 4 UUID string.
     * Uses crypto.randomUUID() when available.
     */
    function generateUuidV4() {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    /**
     * Ensure the form holds a non-empty UUID idempotency key.
     */
    function ensureIdempotencyKey() {
        if (!idempotencyInput) return;
        if (!idempotencyInput.value || idempotencyInput.value.trim() === '') {
            idempotencyInput.value = generateUuidV4();
        }
    }

    // Initialize key
    if (form) {
        ensureIdempotencyKey();
    }

    /**
     * Normalize full-width Japanese IME digits (０-９), full-width yen (￥), and commas.
     *
     * @param {string} str
     * @return {string}
     */
    function normalizeFullWidth(str) {
        if (!str) return '';
        return str
            .replace(/[０-９]/g, function (s) {
                return String.fromCharCode(s.charCodeAt(0) - 0xFEE0);
            })
            .replace(/￥/g, '¥')
            .replace(/[，,]/g, '')
            .trim();
    }

    /**
     * Format a number as integer Japanese Yen with thousands separators.
     *
     * @param {number} val
     * @return {string}
     */
    function formatYen(val) {
        try {
            return new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 0 }).format(val);
        } catch (_) {
            return String(Math.round(val));
        }
    }

    /**
     * Clear all field-level inline error states.
     */
    function clearAllErrors() {
        const errorElements = form.querySelectorAll('.field-error');
        errorElements.forEach(function (el) {
            el.textContent = '';
        });

        const inputs = form.querySelectorAll('.is-invalid');
        inputs.forEach(function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });

        if (errorSummary) {
            errorSummary.classList.add('u-hidden');
            while (errorSummaryList.firstChild) {
                errorSummaryList.removeChild(errorSummaryList.firstChild);
            }
        }
    }

    /**
     * Clear error for a specific field.
     *
     * @param {string} fieldId
     */
    function clearFieldError(fieldId) {
        const errorEl = document.getElementById('error_' + fieldId);
        if (errorEl) {
            errorEl.textContent = '';
        }
        const inputEl = document.getElementById(fieldId);
        if (inputEl) {
            inputEl.classList.remove('is-invalid');
            inputEl.removeAttribute('aria-invalid');
        }
        const sec3Err = document.getElementById('section3_error');
        if (sec3Err && (fieldId === 'available_units' || fieldId === 'price')) {
            sec3Err.textContent = '';
        }
    }

    /**
     * Set an error on a specific field.
     *
     * @param {string} fieldId
     * @param {string} message
     */
    function setFieldError(fieldId, message) {
        const errorEl = document.getElementById('error_' + fieldId);
        if (errorEl) {
            errorEl.textContent = '⚠ ' + message;
        }
        const inputEl = document.getElementById(fieldId);
        if (inputEl) {
            inputEl.classList.add('is-invalid');
            inputEl.setAttribute('aria-invalid', 'true');
        }
    }

    /**
     * Render the top-of-form error summary list and move focus to it.
     *
     * @param {Array<{field: string, message: string}>} errors
     */
    function showErrorSummary(errors) {
        if (!errorSummary || !errorSummaryList) return;
        while (errorSummaryList.firstChild) {
            errorSummaryList.removeChild(errorSummaryList.firstChild);
        }

        errors.forEach(function (err) {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = '#' + err.field;
            link.className = 'error-summary-link';
            link.textContent = err.message;
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.getElementById(err.field);
                if (target) {
                    target.focus();
                }
            });
            li.appendChild(link);
            errorSummaryList.appendChild(li);
        });

        errorSummary.classList.remove('u-hidden');
        errorSummary.focus();
    }

    /**
     * Calculate and update room type helper text and max capacity.
     */
    function updateRoomInfo() {
        const selectedOpt = roomIdSelect.selectedOptions[0];
        if (!selectedOpt || !selectedOpt.value) {
            roomHelperText.textContent = 'Select a room type to view capacity and base price';
            allRoomsBtn.textContent = 'All rooms';
            availableUnitsInput.removeAttribute('max');
            updatePriceComparison();
            updateLiveSummary();
            return;
        }

        const totalUnits = parseInt(selectedOpt.getAttribute('data-total-units') || selectedOpt.getAttribute('data-capacity') || '0', 10);
        const basePrice = parseInt(selectedOpt.getAttribute('data-base-price') || '0', 10);

        roomHelperText.textContent = totalUnits + ' rooms in total · base price ¥' + formatYen(basePrice);
        allRoomsBtn.textContent = 'All rooms (' + totalUnits + ')';
        availableUnitsInput.setAttribute('max', String(totalUnits));

        // Validate units against capacity if already filled
        const currentUnitsVal = availableUnitsInput.value.trim();
        if (currentUnitsVal !== '') {
            const currentUnits = parseInt(currentUnitsVal, 10);
            if (!isNaN(currentUnits) && currentUnits > totalUnits) {
                setFieldError('available_units', 'Cannot exceed room capacity of ' + totalUnits + ' rooms.');
            } else {
                clearFieldError('available_units');
            }
        }

        updatePriceComparison();
        updateLiveSummary();
    }

    /**
     * Compare entered price against selected room's base price.
     */
    function updatePriceComparison() {
        while (priceComparison.firstChild) {
            priceComparison.removeChild(priceComparison.firstChild);
        }

        const selectedOpt = roomIdSelect.selectedOptions[0];
        if (!selectedOpt || !selectedOpt.value) return;

        const basePrice = parseInt(selectedOpt.getAttribute('data-base-price') || '0', 10);
        const rawPrice = normalizeFullWidth(priceInput.value);
        if (rawPrice === '') return;

        const price = parseInt(rawPrice, 10);
        if (isNaN(price) || price < 0 || basePrice <= 0) return;

        const diff = price - basePrice;
        const pct = Math.round((Math.abs(diff) / basePrice) * 100);

        const badge = document.createElement('span');
        badge.className = 'price-comparison-badge';

        if (diff > 0) {
            badge.classList.add('badge-above');
            badge.textContent = '▲ ¥' + formatYen(diff) + ' above the base price (+' + pct + '%)';
        } else if (diff < 0) {
            badge.classList.add('badge-below');
            badge.textContent = '▼ ¥' + formatYen(Math.abs(diff)) + ' below the base price (-' + pct + '%)';
        } else {
            badge.classList.add('badge-equal');
            badge.textContent = '● Same as the base price';
        }

        priceComparison.appendChild(badge);
    }

    /**
     * Format a date string (YYYY-MM-DD) into JST display format: "Sat 25 Oct 2026"
     *
     * @param {string} ymd YYYY-MM-DD
     * @return {string}
     */
    function formatDateJst(ymd) {
        if (!ymd || ymd.length !== 10) return '';
        const parts = ymd.split('-');
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10) - 1;
        const d = parseInt(parts[2], 10);

        // Explicit UTC midnight to prevent any browser time-zone skew
        const dt = new Date(Date.UTC(y, m, d, 12, 0, 0));
        try {
            const formatter = new Intl.DateTimeFormat('en-GB', {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
                year: 'numeric',
                timeZone: 'Asia/Tokyo'
            });
            return formatter.format(dt);
        } catch (_) {
            return ymd;
        }
    }

    /**
     * Compute consecutive day count using UTC midnight arithmetic.
     *
     * @param {string} fromYmd
     * @param {string} toYmd
     * @return {number}
     */
    function countDays(fromYmd, toYmd) {
        if (!fromYmd || !toYmd) return 0;
        const p1 = fromYmd.split('-');
        const p2 = toYmd.split('-');
        const d1 = new Date(Date.UTC(parseInt(p1[0], 10), parseInt(p1[1], 10) - 1, parseInt(p1[2], 10)));
        const d2 = new Date(Date.UTC(parseInt(p2[0], 10), parseInt(p2[1], 10) - 1, parseInt(p2[2], 10)));
        const diffMs = d2 - d1;
        if (diffMs < 0) return 0;
        return Math.round(diffMs / 86400000) + 1;
    }

    /**
     * Update the live date span summary.
     */
    function updateDateSummary() {
        const fromVal = dateFromInput.value;
        const toVal = dateToInput.value;

        if (!fromVal || !toVal) {
            dateLiveSummary.textContent = '';
            updateLiveSummary();
            return;
        }

        const days = countDays(fromVal, toVal);
        if (days <= 0) {
            dateLiveSummary.textContent = '';
            setFieldError('date_to', 'To date cannot be earlier than From date.');
            updateLiveSummary();
            return;
        }

        clearFieldError('date_to');
        const fromFormatted = formatDateJst(fromVal);
        const toFormatted = formatDateJst(toVal);
        const dayLabel = days === 1 ? '1 day' : days + ' days';

        dateLiveSummary.textContent = fromFormatted + ' → ' + toFormatted + ' · ' + dayLabel;
        updateLiveSummary();
    }

    /**
     * Compute a date YYYY-MM-DD offset from the server JST today.
     *
     * @param {number} dayOffset
     * @return {string}
     */
    function addDaysToToday(dayOffset) {
        const parts = serverTodayJst.split('-');
        const dt = new Date(Date.UTC(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10)));
        dt.setUTCDate(dt.getUTCDate() + dayOffset);
        const y = dt.getUTCFullYear();
        const m = String(dt.getUTCMonth() + 1).padStart(2, '0');
        const d = String(dt.getUTCDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    /**
     * Apply date presets.
     *
     * @param {string} preset
     */
    function applyDatePreset(preset) {
        clearFieldError('date_from');
        clearFieldError('date_to');

        if (preset === 'today') {
            dateFromInput.value = serverTodayJst;
            dateToInput.value = serverTodayJst;
        } else if (preset === 'tomorrow') {
            const tom = addDaysToToday(1);
            dateFromInput.value = tom;
            dateToInput.value = tom;
        } else if (preset === 'weekend') {
            // Calculate upcoming or current weekend (Saturday & Sunday)
            const parts = serverTodayJst.split('-');
            const dt = new Date(Date.UTC(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10)));
            const dayOfWeek = dt.getUTCDay(); // 0 = Sun, 6 = Sat

            let satOffset = 0;
            let sunOffset = 0;

            if (dayOfWeek === 6) {
                // Today is Saturday
                satOffset = 0;
                sunOffset = 1;
            } else if (dayOfWeek === 0) {
                // Today is Sunday
                satOffset = -1; // Keep current weekend
                sunOffset = 0;
            } else {
                // Monday through Friday: next Saturday & Sunday
                satOffset = 6 - dayOfWeek;
                sunOffset = satOffset + 1;
            }

            dateFromInput.value = addDaysToToday(satOffset);
            dateToInput.value = addDaysToToday(sunOffset);
        } else if (preset === 'next7') {
            dateFromInput.value = serverTodayJst;
            dateToInput.value = addDaysToToday(6); // 7 days total
        } else if (preset === 'next30') {
            dateFromInput.value = serverTodayJst;
            dateToInput.value = addDaysToToday(29); // 30 days total
        }

        updateDateSummary();
    }

    /**
     * Update the live update summary box before saving.
     */
    function updateLiveSummary() {
        const selectedOpt = roomIdSelect.selectedOptions[0];
        const roomName = (selectedOpt && selectedOpt.value) ? selectedOpt.textContent.trim() : null;
        const fromVal = dateFromInput.value;
        const toVal = dateToInput.value;
        const days = countDays(fromVal, toVal);

        if (!roomName || !fromVal || !toVal || days <= 0) {
            formLiveSummary.textContent = 'Select a room and dates to see update summary';
            return;
        }

        const dateStr = formatDateJst(fromVal) + ' → ' + formatDateJst(toVal) + ' (' + days + ' ' + (days === 1 ? 'day' : 'days') + ')';

        const rawUnits = availableUnitsInput.value.trim();
        const rawPrice = normalizeFullWidth(priceInput.value);

        let unitsSummary = 'units unchanged';
        if (rawUnits !== '') {
            const u = parseInt(rawUnits, 10);
            if (!isNaN(u)) {
                unitsSummary = u + ' room' + (u === 1 ? '' : 's') + ' available';
            }
        }

        let priceSummary = 'price unchanged';
        if (rawPrice !== '') {
            const p = parseInt(rawPrice, 10);
            if (!isNaN(p)) {
                priceSummary = '¥' + formatYen(p) + ' per night';
            }
        }

        formLiveSummary.textContent = roomName + ' · ' + dateStr + ' · ' + unitsSummary + ' · ' + priceSummary;
    }

    /**
     * Show notification card in the result region.
     *
     * @param {'success'|'warning'|'error'} type
     * @param {string} message
     * @param {Array<{text: string, href?: string, action?: Function}>} [links]
     */
    function showResultCard(type, message, links) {
        resultCard.className = 'result-card result-' + type;
        resultCard.classList.remove('u-hidden');

        if (type === 'success') {
            resultIcon.textContent = '✔';
        } else if (type === 'warning') {
            resultIcon.textContent = 'ℹ';
        } else {
            resultIcon.textContent = '⚠️';
        }

        resultMessage.textContent = message;
        liveFeedbackRegion.textContent = message;

        while (resultLinks.firstChild) {
            resultLinks.removeChild(resultLinks.firstChild);
        }

        if (Array.isArray(links) && links.length > 0) {
            links.forEach(function (lnk) {
                if (lnk.href) {
                    const a = document.createElement('a');
                    a.href = lnk.href;
                    a.className = 'result-link';
                    a.textContent = lnk.text;
                    resultLinks.appendChild(a);
                } else if (lnk.action) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-xs btn-text';
                    btn.textContent = lnk.text;
                    btn.addEventListener('click', lnk.action);
                    resultLinks.appendChild(btn);
                }
            });
        }

        resultCard.focus();
    }

    /**
     * Clear result card.
     */
    function clearResultCard() {
        resultCard.classList.add('u-hidden');
        resultMessage.textContent = '';
        while (resultLinks.firstChild) {
            resultLinks.removeChild(resultLinks.firstChild);
        }
    }

    // =========================================================================
    // Form Feature Initializers
    // =========================================================================

    function initRoomCapacityHelper() {
        if (!roomIdSelect) return;
        roomIdSelect.addEventListener('change', function () {
            clearFieldError('room_id');
            updateRoomInfo();
        });
        updateRoomInfo();
    }

    function initDatePresets() {
        if (!dateFromInput || !dateToInput) return;

        dateFromInput.addEventListener('change', function () {
            clearFieldError('date_from');
            if (!dateToInput.value || dateToInput.value < dateFromInput.value) {
                dateToInput.value = dateFromInput.value;
            }
            updateDateSummary();
        });

        dateToInput.addEventListener('change', function () {
            clearFieldError('date_to');
            updateDateSummary();
        });

        document.querySelectorAll('.btn-preset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const preset = this.getAttribute('data-preset');
                if (preset) {
                    applyDatePreset(preset);
                }
            });
        });

        updateDateSummary();
    }

    function initUnitsStepper() {
        if (!unitsMinusBtn || !unitsPlusBtn || !availableUnitsInput) return;

        unitsMinusBtn.addEventListener('click', function () {
            clearFieldError('available_units');
            const currentVal = parseInt(availableUnitsInput.value || '0', 10);
            if (currentVal > 0) {
                availableUnitsInput.value = String(currentVal - 1);
            } else {
                availableUnitsInput.value = '0';
            }
            updateLiveSummary();
        });

        unitsPlusBtn.addEventListener('click', function () {
            clearFieldError('available_units');
            const currentVal = parseInt(availableUnitsInput.value || '-1', 10);
            const selectedOpt = roomIdSelect ? roomIdSelect.selectedOptions[0] : null;
            const max = selectedOpt ? parseInt(selectedOpt.getAttribute('data-total-units') || '999', 10) : 999;
            if (currentVal < max) {
                availableUnitsInput.value = String(currentVal + 1);
            }
            updateLiveSummary();
        });

        availableUnitsInput.addEventListener('input', function () {
            clearFieldError('available_units');
            const selectedOpt = roomIdSelect ? roomIdSelect.selectedOptions[0] : null;
            if (selectedOpt && selectedOpt.value) {
                const totalUnits = parseInt(selectedOpt.getAttribute('data-total-units') || '999', 10);
                const val = parseInt(this.value, 10);
                if (!isNaN(val) && val > totalUnits) {
                    setFieldError('available_units', 'Cannot exceed room capacity of ' + totalUnits + ' rooms.');
                }
            }
            updateLiveSummary();
        });
    }

    function initQuickButtons() {
        if (soldOutBtn && availableUnitsInput) {
            soldOutBtn.addEventListener('click', function () {
                clearFieldError('available_units');
                availableUnitsInput.value = '0';
                updateLiveSummary();
            });
        }

        if (allRoomsBtn && availableUnitsInput && roomIdSelect) {
            allRoomsBtn.addEventListener('click', function () {
                clearFieldError('available_units');
                const selectedOpt = roomIdSelect.selectedOptions[0];
                if (selectedOpt && selectedOpt.value) {
                    const total = selectedOpt.getAttribute('data-total-units') || '0';
                    availableUnitsInput.value = total;
                }
                updateLiveSummary();
            });
        }

        if (startOverBtn && form) {
            startOverBtn.addEventListener('click', function () {
                form.reset();
                clearAllErrors();
                clearResultCard();
                startOverBtn.classList.add('u-hidden');
                if (idempotencyInput) {
                    idempotencyInput.value = generateUuidV4();
                }
                updateRoomInfo();
                if (dateFromInput && dateToInput) {
                    dateFromInput.value = serverTodayJst;
                    dateToInput.value = serverTodayJst;
                    updateDateSummary();
                }
            });
        }
    }

    function initPriceFormatting() {
        if (!priceInput) return;

        priceInput.addEventListener('focus', function () {
            const raw = normalizeFullWidth(this.value);
            if (raw !== '') {
                this.value = raw;
            }
        });

        priceInput.addEventListener('blur', function () {
            const norm = normalizeFullWidth(this.value);
            if (norm === '') {
                this.value = '';
                clearFieldError('price');
                updatePriceComparison();
                updateLiveSummary();
                return;
            }

            const num = parseInt(norm, 10);
            if (isNaN(num) || num < 0) {
                setFieldError('price', 'Price must be a valid whole yen amount.');
            } else {
                clearFieldError('price');
                this.value = formatYen(num);
            }

            updatePriceComparison();
            updateLiveSummary();
        });

        priceInput.addEventListener('input', function () {
            clearFieldError('price');
            updatePriceComparison();
            updateLiveSummary();
        });
    }

    function initSaveForm() {
        if (!form) return;
        form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearAllErrors();
        clearResultCard();

        if (isSubmitting) return;

        // 1. Client-Side Validation
        const errors = [];

        if (!roomIdSelect.value) {
            setFieldError('room_id', 'Please select a room type.');
            errors.push({ field: 'room_id', message: 'Room type is required.' });
        }

        if (!dateFromInput.value) {
            setFieldError('date_from', 'Start date is required.');
            errors.push({ field: 'date_from', message: 'Start date is required.' });
        }

        if (!dateToInput.value) {
            setFieldError('date_to', 'End date is required.');
            errors.push({ field: 'date_to', message: 'End date is required.' });
        } else if (dateFromInput.value && dateToInput.value < dateFromInput.value) {
            setFieldError('date_to', 'End date cannot be earlier than start date.');
            errors.push({ field: 'date_to', message: 'End date cannot be earlier than start date.' });
        }

        const rawUnits = availableUnitsInput.value.trim();
        const rawPrice = normalizeFullWidth(priceInput.value);

        if (rawUnits === '' && rawPrice === '') {
            const sec3Err = document.getElementById('section3_error');
            if (sec3Err) {
                sec3Err.textContent = '⚠ Please specify at least available units or price.';
            }
            errors.push({ field: 'available_units', message: 'Please specify at least available units or price.' });
        }

        if (rawUnits !== '') {
            const u = parseInt(rawUnits, 10);
            const selectedOpt = roomIdSelect.selectedOptions[0];
            const maxUnits = selectedOpt ? parseInt(selectedOpt.getAttribute('data-total-units') || '999', 10) : 999;
            if (isNaN(u) || u < 0) {
                setFieldError('available_units', 'Units must be a non-negative integer.');
                errors.push({ field: 'available_units', message: 'Units must be a non-negative integer.' });
            } else if (u > maxUnits) {
                setFieldError('available_units', 'Units cannot exceed room capacity of ' + maxUnits + ' rooms.');
                errors.push({ field: 'available_units', message: 'Units cannot exceed room capacity of ' + maxUnits + ' rooms.' });
            }
        }

        if (rawPrice !== '') {
            const p = parseInt(rawPrice, 10);
            if (isNaN(p) || p < 0) {
                setFieldError('price', 'Price must be a valid whole yen amount.');
                errors.push({ field: 'price', message: 'Price must be a valid whole yen amount.' });
            }
        }

        if (errors.length > 0) {
            showErrorSummary(errors);
            return;
        }

        // 2. Prepare payload
        ensureIdempotencyKey();

        const formData = new URLSearchParams();
        formData.append('idempotency_key', idempotencyInput.value);
        formData.append('room_id', roomIdSelect.value);
        formData.append('date_from', dateFromInput.value);
        formData.append('date_to', dateToInput.value);
        if (rawUnits !== '') {
            formData.append('available_units', rawUnits);
        }
        if (rawPrice !== '') {
            formData.append('price', rawPrice);
        }

        // Lock form while submitting
        isSubmitting = true;
        submitBtn.disabled = true;
        submitSpinner.classList.remove('u-hidden');
        submitBtnText.textContent = 'Saving...';

        $.ajax({
            url: '/api/inventory.php',
            method: 'POST',
            data: formData.toString(),
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            success: function (response, textStatus, xhr) {
                const status = xhr.status;
                const data = response.data || {};
                const channelCount = (data.channels && data.channels.length) ? data.channels.length : (data.jobs_queued || 3);

                if (data.duplicate === true) {
                    showResultCard('warning', 'Already saved. Nothing changed.', [
                        { text: 'View in sync log →', href: '/log.php' }
                    ]);
                } else {
                    showResultCard('success', 'Saved. Sending to ' + channelCount + ' site' + (channelCount === 1 ? '' : 's') + '.', [
                        { text: 'View in sync log →', href: '/log.php?status=unsynced' },
                        {
                            text: 'Update another',
                            action: function () {
                                availableUnitsInput.value = '';
                                priceInput.value = '';
                                clearAllErrors();
                                clearResultCard();
                                updatePriceComparison();
                                updateLiveSummary();
                                availableUnitsInput.focus();
                            }
                        }
                    ]);

                    /**
                     * WHY A NEW IDEMPOTENCY KEY IS GENERATED ONLY ON SUCCESS:
                     * The previous key was successfully consumed and committed to the database.
                     * Any subsequent submission must be treated as a brand new transaction.
                     * If we reused the key, the server would correctly reject or duplicate-replay it.
                     */
                    idempotencyInput.value = generateUuidV4();

                    // Clear change fields; KEEP room and dates per staff workflow requirements
                    availableUnitsInput.value = '';
                    priceInput.value = '';
                    updatePriceComparison();
                    updateLiveSummary();

                    // Show start over link
                    startOverBtn.classList.remove('u-hidden');

                    // Immediate refresh of the recent updates list
                    fetchRecentUpdates();
                }
            },
            error: function (xhr) {
                let json = null;
                try {
                    json = JSON.parse(xhr.responseText);
                } catch (_) {
                    json = null;
                }

                const status = xhr.status;
                const errorMsg = (json && json.error) ? json.error : 'Could not reach the server. Your change was not lost; press Save again to retry.';

                /**
                 * WHY THE SAME IDEMPOTENCY KEY IS PRESERVED ON ERROR:
                 * On validation error (422), conflict (409), server failure (5xx), or network drop,
                 * no successful transaction occurred. Keeping the exact same key ensures that retrying
                 * the submission remains idempotent, preventing duplicate queue creation if a previous
                 * request managed to hit the database just before network disconnection.
                 */
                if (status === 422 && json && json.fields) {
                    const serverErrors = [];
                    $.each(json.fields, function (f, msg) {
                        setFieldError(f, msg);
                        serverErrors.push({ field: f, message: msg });
                    });
                    showErrorSummary(serverErrors);
                    showResultCard('error', errorMsg);
                } else if (status === 409) {
                    showResultCard('error', errorMsg);
                } else {
                    showResultCard('error', 'Could not reach the server. Your change was not lost; press Save again to retry.');
                }
            },
            complete: function () {
                isSubmitting = false;
                submitBtn.disabled = false;
                submitSpinner.classList.add('u-hidden');
                const selectedOpt = roomIdSelect.selectedOptions[0];
                const activeCount = document.querySelectorAll('.channel-chip').length;
                submitBtnText.textContent = 'Save and send to ' + activeCount + ' site' + (activeCount === 1 ? '' : 's');
            }
        });
    });
    }

    // =========================================================================
    // Recent Updates Poller (Safe DOM creation, Backoff, Visibility)
    // =========================================================================

    /**
     * Compute delivery badge info according to strict state hierarchy:
     * dead > failed > sending > delivered
     *
     * @param {Object} item
     * @return {{className: string, text: string, link: string|null}}
     */
    function computeDeliveryBadge(item) {
        const total = parseInt(item.jobs_total ?? item.total_jobs ?? 0, 10);
        const success = parseInt(item.jobs_success ?? 0, 10);
        const pending = parseInt(item.jobs_pending ?? item.pending_jobs ?? 0, 10);
        const failed = parseInt(item.jobs_failed ?? 0, 10);
        const dead = parseInt(item.jobs_dead ?? 0, 10);

        if (dead > 0) {
            return {
                className: 'badge-danger',
                text: '✖ Needs attention ' + dead + '/' + total,
                link: '/log.php?status=dead'
            };
        }
        if (failed > 0) {
            return {
                className: 'badge-warning',
                text: '⚠ Retrying ' + failed + '/' + total,
                link: null
            };
        }
        if (pending > 0) {
            return {
                className: 'badge-info',
                text: '⟳ Sending ' + pending + '/' + total,
                link: null
            };
        }
        if (total > 0 && success === total) {
            return {
                className: 'badge-success',
                text: '✔ Delivered ' + success + '/' + total,
                link: null
            };
        }
        return {
            className: 'badge-muted',
            text: '–',
            link: null
        };
    }

    /**
     * Render a recent update row safely using createElement and textContent.
     *
     * @param {Object} item
     * @return {HTMLTableRowElement}
     */
    function renderRecentRow(item) {
        const tr = document.createElement('tr');

        // Column 1: Time & ID
        const tdTime = document.createElement('td');
        tdTime.className = 'col-time';
        const timeDiv = document.createElement('div');
        timeDiv.className = 'time-primary';

        // Extract hour:minute
        const createdJst = item.created_at_jst || '';
        let timeDisplay = createdJst;
        if (createdJst.includes(' ')) {
            const timePart = createdJst.split(' ')[1] || '';
            const hm = timePart.slice(0, 5);
            timeDisplay = hm || createdJst;
        }
        timeDiv.textContent = timeDisplay;

        const idDiv = document.createElement('div');
        idDiv.className = 'time-id';
        idDiv.textContent = '#' + item.id;

        tdTime.appendChild(timeDiv);
        tdTime.appendChild(idDiv);
        tr.appendChild(tdTime);

        // Column 2: Room Name
        const tdRoom = document.createElement('td');
        tdRoom.className = 'col-room';
        const strongRoom = document.createElement('strong');
        strongRoom.textContent = item.room_name || 'Room';
        tdRoom.appendChild(strongRoom);
        tr.appendChild(tdRoom);

        // Column 3: Dates
        const tdDates = document.createElement('td');
        tdDates.className = 'col-dates';
        tdDates.textContent = (item.date_from || '') + ' → ' + (item.date_to || '');
        tr.appendChild(tdDates);

        // Column 4: Change Chips
        const tdChange = document.createElement('td');
        tdChange.className = 'col-change';
        const chipsGroup = document.createElement('div');
        chipsGroup.className = 'change-chips';

        let hasChange = false;
        if (item.available_units !== null && item.available_units !== undefined) {
            const uChip = document.createElement('span');
            uChip.className = 'chip chip-sm';
            uChip.textContent = item.available_units + ' rooms';
            chipsGroup.appendChild(uChip);
            hasChange = true;
        }

        if (item.price !== null && item.price !== undefined) {
            const pChip = document.createElement('span');
            pChip.className = 'chip chip-sm';
            pChip.textContent = '¥' + formatYen(item.price);
            chipsGroup.appendChild(pChip);
            hasChange = true;
        }

        if (!hasChange) {
            const mutedChip = document.createElement('span');
            mutedChip.className = 'chip chip-muted';
            mutedChip.textContent = 'No change';
            chipsGroup.appendChild(mutedChip);
        }

        tdChange.appendChild(chipsGroup);
        tr.appendChild(tdChange);

        // Column 5: Delivery Badge
        const tdDelivery = document.createElement('td');
        tdDelivery.className = 'col-delivery';
        const badgeInfo = computeDeliveryBadge(item);

        if (badgeInfo.link) {
            const a = document.createElement('a');
            a.href = badgeInfo.link;
            a.className = 'badge ' + badgeInfo.className;
            a.textContent = badgeInfo.text;
            tdDelivery.appendChild(a);
        } else {
            const span = document.createElement('span');
            span.className = 'badge ' + badgeInfo.className;
            span.textContent = badgeInfo.text;
            tdDelivery.appendChild(span);
        }
        tr.appendChild(tdDelivery);

        return tr;
    }

    /**
     * Poll /api/recent.php and update recent table cleanly.
     */
    function fetchRecentUpdates() {
        const requestId = ++recentPollCounter;

        $.ajax({
            url: '/api/recent.php',
            method: 'GET',
            dataType: 'json',
            cache: false,
            success: function (response) {
                // Ignore out-of-order responses
                if (requestId !== recentPollCounter) return;

                recentBackoffIdx = 0; // Reset backoff on success
                if (recentStaleNotice) {
                    recentStaleNotice.classList.add('u-hidden');
                }

                if (!response || !response.ok || !Array.isArray(response.data)) return;

                while (recentTbody.firstChild) {
                    recentTbody.removeChild(recentTbody.firstChild);
                }

                if (response.data.length === 0) {
                    const emptyTr = document.createElement('tr');
                    emptyTr.className = 'empty-row';
                    const emptyTd = document.createElement('td');
                    emptyTd.colSpan = 5;
                    emptyTd.className = 'empty-cell';
                    emptyTd.textContent = 'No updates yet. Your first change will appear here.';
                    emptyTr.appendChild(emptyTd);
                    recentTbody.appendChild(emptyTr);
                    return;
                }

                response.data.forEach(function (item) {
                    recentTbody.appendChild(renderRecentRow(item));
                });
            },
            error: function () {
                if (requestId !== recentPollCounter) return;
                // Back off and display subtle stale mark
                if (recentStaleNotice) {
                    recentStaleNotice.classList.remove('u-hidden');
                }
                recentBackoffIdx = Math.min(recentBackoffIdx + 1, recentBackoffDelays.length - 1);
            },
            complete: function () {
                if (requestId !== recentPollCounter) return;
                scheduleNextRecentPoll();
            }
        });
    }

    /**
     * Schedule next poll with setTimeout (never setInterval).
     */
    function scheduleNextRecentPoll() {
        if (recentPollTimeoutId) {
            clearTimeout(recentPollTimeoutId);
        }
        if (document.hidden) return; // Pause while tab is hidden

        const delay = recentBackoffDelays[recentBackoffIdx] || 10000;
        recentPollTimeoutId = setTimeout(fetchRecentUpdates, delay);
    }

    function initRecentPolling() {
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                fetchRecentUpdates();
            } else if (recentPollTimeoutId) {
                clearTimeout(recentPollTimeoutId);
                recentPollTimeoutId = null;
            }
        });

        scheduleNextRecentPoll();
    }

    // =========================================================================
    // Independent Module Initialization
    // =========================================================================
    try { initTheme(); } catch (err) { console.error('initTheme failed', err); }
    try { initWallMode(); } catch (err) { console.error('initWallMode failed', err); }

    if (form) {
        try { initRoomCapacityHelper(); } catch (err) { console.error('initRoomCapacityHelper failed', err); }
        try { initDatePresets(); } catch (err) { console.error('initDatePresets failed', err); }
        try { initUnitsStepper(); } catch (err) { console.error('initUnitsStepper failed', err); }
        try { initQuickButtons(); } catch (err) { console.error('initQuickButtons failed', err); }
        try { initPriceFormatting(); } catch (err) { console.error('initPriceFormatting failed', err); }
        try { initSaveForm(); } catch (err) { console.error('initSaveForm failed', err); }
        try { initRecentPolling(); } catch (err) { console.error('initRecentPolling failed', err); }
    }
});
