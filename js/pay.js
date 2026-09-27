// SQUARE_APPLICATION_ID, SQUARE_LOCATION_ID, BOOKING_ID, BOOKING_TOKEN are injected inline in pay.php.

let card;
let submitting = false;

async function initializeCard() {
    const payments = window.Square.payments(SQUARE_APPLICATION_ID, SQUARE_LOCATION_ID);
    card = await payments.card();
    await card.attach('#card-container');
}

function showError(message) {
    const el = document.getElementById('pay-error');
    el.textContent = message;
    el.hidden = false;
    document.getElementById('pay-success').hidden = true;
}

function showSuccess(message) {
    const el = document.getElementById('pay-success');
    el.textContent = message;
    el.hidden = false;
    document.getElementById('pay-error').hidden = true;
}

function setSubmitting(isSubmitting) {
    submitting = isSubmitting;
    const btn = document.getElementById('card-button');
    btn.disabled = isSubmitting;
    btn.textContent = isSubmitting ? 'Processing…' : btn.dataset.defaultLabel;
}

async function sendPayment(sourceId) {
    const idempotencyKey = (window.crypto && crypto.randomUUID)
        ? crypto.randomUUID()
        : (Date.now() + '-' + Math.random().toString(16).slice(2));

    const response = await fetch('process_payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            booking_id: BOOKING_ID,
            token: BOOKING_TOKEN,
            source_id: sourceId,
            idempotency_key: idempotencyKey,
        }),
    });

    let data;
    try {
        data = await response.json();
    } catch (e) {
        throw new Error('Unexpected response from the server.');
    }

    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Payment could not be completed.');
    }

    return data;
}

document.addEventListener('DOMContentLoaded', async function () {
    const button = document.getElementById('card-button');
    button.dataset.defaultLabel = button.textContent;

    try {
        await initializeCard();
    } catch (e) {
        showError('Could not load the payment form. Please refresh the page or try again shortly.');
        button.disabled = true;
        return;
    }

    button.addEventListener('click', async function (event) {
        event.preventDefault();
        if (submitting) return;

        setSubmitting(true);
        document.getElementById('pay-error').hidden = true;

        try {
            const tokenResult = await card.tokenize();
            if (tokenResult.status !== 'OK') {
                const detail = (tokenResult.errors || []).map(e => e.message).join(' ');
                throw new Error(detail || 'Please check your card details and try again.');
            }

            const result = await sendPayment(tokenResult.token);

            document.getElementById('payment-form').style.display = 'none';
            showSuccess(result.alreadyPaid
                ? 'This booking is already paid in full.'
                : 'Payment received — your bay is confirmed! A receipt has been sent by Square.');

            if (result.receiptUrl) {
                const link = document.createElement('a');
                link.href = result.receiptUrl;
                link.target = '_blank';
                link.rel = 'noopener';
                link.textContent = 'View your Square receipt';
                link.style.display = 'inline-block';
                link.style.marginTop = '10px';
                document.getElementById('pay-success').appendChild(document.createElement('br'));
                document.getElementById('pay-success').appendChild(link);
            }
        } catch (err) {
            showError(err.message || 'Something went wrong. Please try again.');
            setSubmitting(false);
        }
    });
});
