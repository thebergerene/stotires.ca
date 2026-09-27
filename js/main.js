// PACKAGE_PRICES, ZONE_SURCHARGES, ZONE_WEEKDAYS, ZONE_DAY_LABELS,
// SQUARE_APPLICATION_ID, SQUARE_LOCATION_ID are injected inline in index.php.
// ZONE_WEEKDAYS values use ISO weekday numbers: Mon=1 ... Sun=7 (matches PHP's date('N')).

let card = null;
let submitting = false;
let deliveryEditedByUser = false;
// Generated once per page load and reused across retries, so a resubmission
// after a network hiccup can't accidentally double-charge the same card.
const idempotencyKey = (window.crypto && crypto.randomUUID)
    ? crypto.randomUUID()
    : (Date.now() + '-' + Math.random().toString(16).slice(2));

function selectPackage(packageId) {
    document.getElementById('package_id').value = packageId;
    document.getElementById('package_select').value = packageId;
    updateEstimate();
}

function getSelectedZoneId() {
    const checked = document.querySelector('input[name="zone_id"]:checked');
    return checked ? checked.value : null;
}

function formatISO(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + d;
}

function isoWeekdayFromDate(date) {
    const js = date.getDay(); // 0=Sun..6=Sat
    return js === 0 ? 7 : js;
}

function startOfToday() {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    return d;
}

// The month currently shown in the pickup calendar popup.
let calendarViewDate = (() => {
    const d = startOfToday();
    d.setDate(1);
    return d;
})();

function toggleCalendar() {
    const popup = document.getElementById('pickup-calendar');
    if (popup.hidden) {
        renderCalendar();
        popup.hidden = false;
    } else {
        popup.hidden = true;
    }
}

function calendarChangeMonth(delta) {
    calendarViewDate = new Date(calendarViewDate.getFullYear(), calendarViewDate.getMonth() + delta, 1);
    renderCalendar();
}

function selectCalendarDate(dateStr) {
    document.getElementById('pickup_date').value = dateStr;
    document.getElementById('pickup_date_display').value = new Date(dateStr + 'T00:00:00')
        .toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
    document.getElementById('pickup-calendar').hidden = true;
    onPickupDateChange();
    renderCalendar();
}

function renderCalendar() {
    const container = document.getElementById('pickup-calendar');
    const zoneId = getSelectedZoneId();
    const allowed = (zoneId && ZONE_WEEKDAYS[zoneId]) ? ZONE_WEEKDAYS[zoneId] : [1, 2, 3, 4, 5, 6, 7];
    const today = startOfToday();
    const selected = document.getElementById('pickup_date').value;

    const year = calendarViewDate.getFullYear();
    const month = calendarViewDate.getMonth();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    // getDay() is 0=Sun..6=Sat; shift so the grid starts on Monday.
    const startOffset = (new Date(year, month, 1).getDay() + 6) % 7;

    let html = '<div class="cal-header">' +
        '<button type="button" aria-label="Previous month" onclick="calendarChangeMonth(-1)">&lsaquo;</button>' +
        '<span>' + calendarViewDate.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }) + '</span>' +
        '<button type="button" aria-label="Next month" onclick="calendarChangeMonth(1)">&rsaquo;</button>' +
        '</div><div class="cal-grid">';

    ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].forEach(function (label) {
        html += '<div class="cal-dow">' + label + '</div>';
    });
    for (let i = 0; i < startOffset; i++) {
        html += '<div class="cal-day empty"></div>';
    }
    for (let day = 1; day <= daysInMonth; day++) {
        const date = new Date(year, month, day);
        const dateStr = formatISO(date);
        const isPast = date < today;
        const isAllowedDay = allowed.includes(isoWeekdayFromDate(date));
        const clickable = isAllowedDay && !isPast;
        const classes = ['cal-day'];
        if (!clickable) classes.push('cal-disabled');
        if (dateStr === selected) classes.push('cal-selected');
        html += '<button type="button" class="' + classes.join(' ') + '"' +
            (clickable ? ' onclick="selectCalendarDate(\'' + dateStr + '\')"' : ' disabled') +
            '>' + day + '</button>';
    }
    html += '</div>';
    container.innerHTML = html;
}

// Mirrors book_and_pay.php's add_calendar_months(): adds whole calendar
// months and clamps to the last valid day of the target month (e.g. Aug 31 +
// 6 months lands on Feb 28/29, not an overflowed "Mar 3").
function addCalendarMonths(dateStr, months) {
    const d = new Date(dateStr + 'T00:00:00');
    let year = d.getFullYear();
    let month = d.getMonth() + 1 + months; // getMonth() is 0-indexed
    const day = d.getDate();

    while (month > 12) {
        month -= 12;
        year += 1;
    }

    const daysInTargetMonth = new Date(year, month, 0).getDate(); // day 0 of next month = last day of this one
    const clampedDay = Math.min(day, daysInTargetMonth);

    const result = new Date(year, month - 1, clampedDay);
    return result.toISOString().slice(0, 10);
}

function onZoneChange() {
    const zoneId = getSelectedZoneId();
    const hint = document.getElementById('pickup-hint');
    const pickupInput = document.getElementById('pickup_date');
    const displayInput = document.getElementById('pickup_date_display');
    const errorEl = document.getElementById('pickup-error');

    if (zoneId && ZONE_DAY_LABELS[zoneId]) {
        hint.textContent = 'Pickup day for your area: ' + ZONE_DAY_LABELS[zoneId] + '.';
    }

    // A previously chosen date might not be valid for the newly selected
    // zone — the calendar only lets you pick valid days, but switching zones
    // after picking a date can invalidate that choice, so clear it rather
    // than silently moving it to a different date the customer didn't pick.
    if (zoneId && pickupInput.value) {
        const allowed = ZONE_WEEKDAYS[zoneId] || [];
        const selectedDate = new Date(pickupInput.value + 'T00:00:00');
        if (!allowed.includes(isoWeekdayFromDate(selectedDate))) {
            pickupInput.value = '';
            displayInput.value = '';
            errorEl.textContent = 'Your previous pickup date isn\u2019t available for this area (' +
                ZONE_DAY_LABELS[zoneId] + '). Please choose a new date.';
            errorEl.hidden = false;
        }
    }

    renderCalendar();
    updateEstimate();
}

function onPickupDateChange() {
    const pickupInput = document.getElementById('pickup_date');
    const deliveryInput = document.getElementById('delivery_date');
    const errorEl = document.getElementById('pickup-error');

    if (!pickupInput.value) return;

    errorEl.hidden = true;
    deliveryInput.min = pickupInput.value;
    if (!deliveryEditedByUser) {
        deliveryInput.value = addCalendarMonths(pickupInput.value, 6);
    }
    updateEstimate();
}

function updateEstimate() {
    const packageId = document.getElementById('package_id').value;
    const zoneId = getSelectedZoneId();
    const quantity = parseInt(document.getElementById('quantity').value, 10) || 1;
    const box = document.getElementById('estimate');
    const amountEl = document.getElementById('estimate-amount');
    const breakdownEl = document.getElementById('estimate-breakdown');

    const packagePrice = PACKAGE_PRICES[packageId];
    if (packagePrice === undefined) {
        box.hidden = true;
        return;
    }

    const zoneSurcharge = zoneId ? (ZONE_SURCHARGES[zoneId] || 0) : 0;
    const setsTotal = packagePrice * quantity;
    const total = setsTotal + zoneSurcharge;

    amountEl.textContent = '$' + (total / 100).toFixed(2);
    const parts = [];
    parts.push((quantity > 1 ? quantity + ' sets @ $' + (packagePrice / 100).toFixed(0) : '1 set') + ' = $' + (setsTotal / 100).toFixed(0));
    parts.push(zoneSurcharge > 0 ? 'area surcharge $' + (zoneSurcharge / 100).toFixed(0) : 'no area surcharge');
    breakdownEl.textContent = ' (' + parts.join(' + ') + ')';
    box.hidden = false;

    // Keep the Pay button's dollar figure in sync too.
    const btn = document.getElementById('book-button');
    if (btn && !submitting) {
        btn.textContent = 'Pay $' + (total / 100).toFixed(2) + ' & reserve my bay';
    }
}

function showBookingError(message) {
    const el = document.getElementById('booking-error');
    el.textContent = message;
    el.hidden = false;
    document.getElementById('booking-success').hidden = true;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function showBookingSuccess(message) {
    const el = document.getElementById('booking-success');
    el.textContent = message;
    el.hidden = false;
    document.getElementById('booking-error').hidden = true;
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function setSubmitting(isSubmitting) {
    submitting = isSubmitting;
    const btn = document.getElementById('book-button');
    btn.disabled = isSubmitting;
    if (isSubmitting) {
        btn.dataset.restoreLabel = btn.textContent;
        btn.textContent = 'Processing…';
    } else if (btn.dataset.restoreLabel) {
        btn.textContent = btn.dataset.restoreLabel;
    }
}

async function initializeCard() {
    const payments = window.Square.payments(SQUARE_APPLICATION_ID, SQUARE_LOCATION_ID);
    // Square auto-labels the postal/ZIP field per the card's issuing country regardless
    // of this setting, but setLocale is still the right call for a Canadian business —
    // it governs other localized text/formatting in the card field.
    if (typeof payments.setLocale === 'function') {
        payments.setLocale('en-CA');
    }
    card = await payments.card();
    await card.attach('#card-container');
}

function collectBookingPayload(sourceId) {
    const form = document.getElementById('booking-form');
    const zoneId = getSelectedZoneId();
    return {
        package_id: form.package_id.value,
        zone_id: zoneId,
        quantity: form.quantity.value,
        full_name: form.full_name.value,
        email: form.email.value,
        phone: form.phone.value,
        address: form.address.value,
        vehicle_info: form.vehicle_info.value,
        tire_size: form.tire_size.value,
        pickup_date: form.pickup_date.value,
        delivery_date: form.delivery_date.value,
        notes: form.notes.value,
        source_id: sourceId,
        idempotency_key: idempotencyKey,
    };
}

async function submitBooking() {
    if (submitting) return;

    const form = document.getElementById('booking-form');
    if (!form.reportValidity()) return; // native required-field validation

    setSubmitting(true);
    document.getElementById('booking-error').hidden = true;

    try {
        const tokenResult = await card.tokenize();
        if (tokenResult.status !== 'OK') {
            const detail = (tokenResult.errors || []).map(e => e.message).join(' ');
            throw new Error(detail || 'Please check your card details and try again.');
        }

        let response, data;
        try {
            response = await fetch('book_and_pay.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(collectBookingPayload(tokenResult.token)),
            });
            data = await response.json();
        } catch (networkErr) {
            // We genuinely don't know whether the server received this request.
            showBookingError(
                'We lost connection while confirming your booking. Before trying again, please check your email ' +
                'for a receipt — if you already received one, don\u2019t resubmit. Otherwise, try again in a moment.'
            );
            setSubmitting(false);
            return;
        }

        if (!data.success) {
            showBookingError(data.message || 'Your booking could not be completed. Please try again.');
            setSubmitting(false);
            return;
        }

        document.getElementById('booking-form').style.display = 'none';
        showBookingSuccess('Payment received — your bay is booked and confirmed! A confirmation has been sent to your email.');

        const successEl = document.getElementById('booking-success');

        if (data.bookingId && data.accessToken) {
            successEl.appendChild(document.createElement('br'));
            const confirmLink = document.createElement('a');
            confirmLink.href = 'pay.php?booking=' + encodeURIComponent(data.bookingId) + '&token=' + encodeURIComponent(data.accessToken);
            confirmLink.textContent = 'View your booking summary';
            successEl.appendChild(confirmLink);
        }

        if (data.receiptUrl) {
            successEl.appendChild(document.createElement('br'));
            const link = document.createElement('a');
            link.href = data.receiptUrl;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = 'View your Square receipt';
            successEl.appendChild(link);
        }
    } catch (err) {
        showBookingError(err.message || 'Something went wrong. Please try again.');
        setSubmitting(false);
    }
}

document.addEventListener('DOMContentLoaded', async function () {
    const deliveryInput = document.getElementById('delivery_date');
    if (deliveryInput) {
        deliveryInput.addEventListener('input', function () {
            deliveryEditedByUser = true;
        });
    }

    document.addEventListener('click', function (e) {
        const popup = document.getElementById('pickup-calendar');
        const displayInput = document.getElementById('pickup_date_display');
        if (!popup.hidden && !popup.contains(e.target) && e.target !== displayInput) {
            popup.hidden = true;
        }
    });

    onZoneChange();
    updateEstimate();

    const bookButton = document.getElementById('book-button');
    try {
        await initializeCard();
    } catch (e) {
        showBookingError('Could not load the payment form. Please refresh the page or try again shortly.');
        bookButton.disabled = true;
        return;
    }

    bookButton.addEventListener('click', submitBooking);
});
