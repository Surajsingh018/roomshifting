"use strict";

const apiBase = document.body.dataset.apiBase;
const makeKey = () => (window.crypto && crypto.randomUUID)
  ? crypto.randomUUID()
  : `rs-${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;

async function postJson(path, payload) {
  const response = await fetch(`${apiBase}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify(payload),
    credentials: "same-origin"
  });
  let envelope;
  try { envelope = await response.json(); } catch { throw new Error("The server returned an unreadable response."); }
  if (!response.ok || envelope.success !== true) throw new Error(envelope.message || "The request could not be completed.");
  return envelope.data;
}

function parseInventory(form) {
  const field = form.querySelector("#inventory");
  if (!field || !field.value.trim()) return [];
  return field.value.split(/\r?\n/).filter(Boolean).map((line) => {
    const [item, quantityText] = line.split(",");
    const quantity = Number.parseInt(quantityText, 10);
    if (!item || !Number.isInteger(quantity) || quantity < 1) throw new Error("Inventory rows must use item,quantity format.");
    return { item: item.trim(), quantity };
  });
}

function renderQuote(container, breakdown) {
  container.replaceChildren();
  const list = document.createElement("dl");
  list.className = "quote-breakdown";
  const entries = [
    ["Base price", breakdown.base_price],
    [`Distance (${breakdown.distance_km} km)`, breakdown.distance_charge],
    [`Floors (${breakdown.floors})`, breakdown.floor_charge],
    ["Packing", breakdown.packing_charges],
    ["Subtotal", breakdown.subtotal],
    [`GST (${breakdown.gst_percent}%)`, breakdown.gst],
    ["Total", breakdown.total]
  ];
  entries.forEach(([label, amount], index) => {
    const term = document.createElement("dt");
    const value = document.createElement("dd");
    term.textContent = label;
    value.textContent = `₹${amount}`;
    if (index === entries.length - 1) { term.className = "quote-total-label"; value.className = "quote-total"; }
    list.append(term, value);
  });
  container.append(list);
}

document.querySelectorAll(".js-enquiry-form").forEach((form) => {
  const status = form.querySelector("#formStatus");
  const submit = form.querySelector("[data-submit]");
  const quoteResult = document.getElementById("quoteResult");
  const createBooking = document.getElementById("createBooking");
  const bookingResult = document.getElementById("bookingResult");
  let idempotencyKey = makeKey();
  let enquiryToken = null;
  let quoteId = null;
  let quoteToken = null;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;
    status.textContent = "Sending your move details…";
    status.className = "alert alert-info mt-3";
    submit.disabled = true;
    const payload = Object.fromEntries(new FormData(form).entries());
    payload.idempotency_key = idempotencyKey;
    payload.service_id = Number(payload.service_id);
    payload.floors = Number(payload.floors || 0);
    payload.distance_km = Number(payload.distance_km);
    payload.packing_required = payload.packing_required === "true";
    try {
      payload.inventory = parseInventory(form);
      const enquiry = await postJson("/enquiries", payload);
      if (form.dataset.mode === "contact") {
        status.textContent = `Your enquiry was received. Reference #${enquiry.id}.`;
        status.className = "alert alert-success mt-3";
        idempotencyKey = makeKey();
        form.reset();
        return;
      }
      enquiryToken = enquiry.enquiry_token;
      if (typeof enquiryToken !== "string" || !/^[a-f0-9]{64}$/.test(enquiryToken)) {
        throw new Error("The server did not return a secure enquiry reference. Please retry.");
      }
      status.textContent = "Enquiry saved. Calculating your server quote…";
      const quote = await postJson("/quotes/calculate", { enquiry_id: enquiry.id, enquiry_token: enquiryToken });
      quoteId = quote.quote_id;
      quoteToken = quote.quote_token;
      if (typeof quoteToken !== "string" || !/^[a-f0-9]{64}$/.test(quoteToken)) {
        throw new Error("The server did not return a secure quote reference. Please retry.");
      }
      renderQuote(quoteResult, quote.breakdown);
      createBooking.hidden = false;
      status.textContent = "Your quote is ready. Review the breakdown and create a booking when you are ready.";
      status.className = "alert alert-success mt-3";
    } catch (error) {
      status.textContent = error.message || "We could not submit your request. Please try again.";
      status.className = "alert alert-danger mt-3";
    } finally {
      submit.disabled = false;
    }
  });

  if (createBooking) createBooking.addEventListener("click", async () => {
    if (!quoteId) return;
    createBooking.disabled = true;
    bookingResult.textContent = "Creating your booking…";
    try {
      const booking = await postJson("/bookings", { quote_id: quoteId, quote_token: quoteToken, idempotency_key: `${idempotencyKey}-booking` });
      bookingResult.replaceChildren();
      const paragraph = document.createElement("p");
      paragraph.textContent = `Booking ${booking.booking_ref} is recorded with status “${booking.status}”.`;
      const code = document.createElement("p");
      code.textContent = `Keep this tracking code private: ${booking.tracking_code}`;
      const link = document.createElement("a");
      link.href = `${apiBase}/bookings/track?code=${encodeURIComponent(booking.tracking_code)}`;
      link.textContent = "Open live tracking";
      link.rel = "noopener";
      bookingResult.append(paragraph, code, link);
    } catch (error) {
      bookingResult.textContent = error.message || "The booking could not be created. Retry safely with the same request.";
    } finally {
      createBooking.disabled = false;
    }
  });
});
