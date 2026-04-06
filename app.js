// app.js – Frontend interactions for ParkCampus

// ─── Core API helper ──────────────────────────────────────
async function apiPost(url, data) {
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        return await res.json();
    } catch (e) {
        return { ok: false, message: 'Network error' };
    }
}

// ─── Modal helpers ────────────────────────────────────────
function openModal(id) {
    document.getElementById(id)?.classList.add('active');
}
function closeModal(id) {
    document.getElementById(id)?.classList.remove('active');
}
// Close on backdrop click
document.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('active');
    }
});

// ─── Toast auto-hide ──────────────────────────────────────
document.querySelectorAll('.toast[data-auto-hide]').forEach(toast => {
    const ms = parseInt(toast.dataset.autoHide) || 5000;
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.4s ease forwards';
        setTimeout(() => toast.remove(), 400);
    }, ms);
});

// ─── Book Slot ────────────────────────────────────────────
let _pendingBook = null;

function bookSlot(slotId, slotCode, timetableId, startTime, endTime, classDate) {
    _pendingBook = { slot_id: slotId, timetable_id: timetableId, class_date: classDate };
    const lang = typeof LANG !== 'undefined' ? LANG : 'ms';
    const body = lang === 'ms'
        ? `<p>Tempah <strong>${slotCode}</strong> untuk kelas <strong>${startTime.slice(0,5)}–${endTime.slice(0,5)}</strong>?</p>
           <p class="muted-text">Tempoh masa masuk: 15 minit selepas pengesahan.</p>`
        : `<p>Book slot <strong>${slotCode}</strong> for class <strong>${startTime.slice(0,5)}–${endTime.slice(0,5)}</strong>?</p>
           <p class="muted-text">Grace window: 15 minutes after confirmation.</p>`;
    document.getElementById('bookModalBody').innerHTML = body;
    openModal('bookModal');
}

document.getElementById('bookConfirmBtn')?.addEventListener('click', async () => {
    if (!_pendingBook) return;
    const btn = document.getElementById('bookConfirmBtn');
    btn.disabled = true;
    btn.textContent = '…';

    const res = await apiPost('api.php', { action: 'book_slot', ..._pendingBook });
    if (res.ok) {
        closeModal('bookModal');
        showToast('✅ ' + res.message, 'success');
        setTimeout(() => location.reload(), 1500);
    } else {
        showToast('❌ ' + res.message, 'error');
        btn.disabled = false;
        btn.textContent = typeof LANG !== 'undefined' && LANG === 'ms' ? 'Ya, Tempah' : 'Yes, Book';
    }
    _pendingBook = null;
});

// ─── Cancel Booking ───────────────────────────────────────
async function cancelBooking(bookingId) {
    const lang = typeof LANG !== 'undefined' ? LANG : 'ms';
    const msg  = lang === 'ms' ? 'Batalkan tempahan ini?' : 'Cancel this booking?';
    if (!confirm(msg)) return;
    const res = await apiPost('api.php', { action: 'cancel_booking', booking_id: bookingId });
    if (res.ok) { showToast('✅ ' + res.message, 'success'); setTimeout(() => location.reload(), 1000); }
    else showToast('❌ ' + res.message, 'error');
}

// ─── Admin Cancel ─────────────────────────────────────────
async function adminCancel(bookingId, studentId) {
    const lang = typeof LANG !== 'undefined' ? LANG : 'ms';
    const msg  = lang === 'ms' ? `Batalkan tempahan #${bookingId} untuk pelajar ${studentId}?` : `Cancel booking #${bookingId} for student ${studentId}?`;
    if (!confirm(msg)) return;
    const res = await apiPost('api.php', { action: 'admin_cancel', booking_id: bookingId, reason: 'admin_override' });
    if (res.ok) { showToast('✅ Done', 'success'); setTimeout(() => location.reload(), 1000); }
    else showToast('❌ ' + res.message, 'error');
}

// ─── Extend Booking ───────────────────────────────────────
let _extendBookingId = null;

function openExtend(bookingId, currentEnd, timetableId) {
    _extendBookingId = bookingId;
    // Prefill time input
    const t = currentEnd.includes('T') ? currentEnd.split('T')[1].slice(0,5) : currentEnd.split(' ')[1].slice(0,5);
    document.getElementById('newEndTime').value = t;
    openModal('extendModal');
}

document.getElementById('extendConfirmBtn')?.addEventListener('click', async () => {
    const newEnd = document.getElementById('newEndTime').value;
    if (!newEnd || !_extendBookingId) return;
    const res = await apiPost('api.php', { action: 'extend_booking', booking_id: _extendBookingId, new_end_time: newEnd });
    if (res.ok) {
        closeModal('extendModal');
        showToast('✅ Extended to ' + newEnd, 'success');
        setTimeout(() => location.reload(), 1000);
    } else {
        showToast('❌ ' + res.message, 'error');
    }
});

// ─── Toast helper ─────────────────────────────────────────
function showToast(msg, type = 'info') {
    const t = document.createElement('div');
    t.className = `toast toast--${type}`;
    t.innerHTML = `<span>${msg}</span>`;
    document.body.appendChild(t);
    setTimeout(() => { t.style.animation = 'slideOut 0.4s ease forwards'; setTimeout(() => t.remove(), 400); }, 4000);
}

// ─── Real-time clock in nav ───────────────────────────────
const clockEl = document.getElementById('liveClock');
if (clockEl) {
    const tick = () => {
        const now = new Date();
        clockEl.textContent = now.toLocaleTimeString('ms-MY', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    };
    tick();
    setInterval(tick, 1000);
}
