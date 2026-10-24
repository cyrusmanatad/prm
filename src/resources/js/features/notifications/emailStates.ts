export const emailStates: Record<string, string> = {
    pending: 'Email pending',
    sending: 'Email sending',
    sent: 'Email accepted by mail server; receipt unconfirmed',
    captured: 'Email captured locally; no external delivery',
    cancelled: 'Email cancelled',
    uncertain: 'Email delivery uncertain; review required before any new send',
};
