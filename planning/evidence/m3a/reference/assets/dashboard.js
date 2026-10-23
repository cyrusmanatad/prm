/* UI-only prototype behavior; state resets on refresh. */
document.querySelectorAll("[data-dismiss]").forEach((button) => {
  button.addEventListener("click", () => button.closest("[data-action]").remove());
});

document.querySelectorAll("[data-switch]").forEach((button) => {
  button.addEventListener("click", () => {
    const on = button.getAttribute("aria-pressed") !== "true";
    button.setAttribute("aria-pressed", String(on));
    button.setAttribute("aria-label", `${on ? "Turn off" : "Turn on"} ${button.dataset.switch}`);
  });
});

document.querySelectorAll("[data-nudge]").forEach((button) => {
  button.addEventListener("click", () => {
    button.dataset.sent = "";
    button.textContent = "Sent";
  });
});

document.querySelectorAll("[data-approve]").forEach((button) => {
  button.addEventListener("click", () => {
    const status = button.closest("[data-registration]").querySelector("[data-status]");
    status.dataset.status = "approved";
    status.textContent = "Approved";
    button.remove();
  });
});
