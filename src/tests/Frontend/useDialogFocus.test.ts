import { afterEach, describe, expect, it } from 'vitest';
import { containDialogFocus } from '../../resources/js/composables/useDialogFocus';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('dialog focus traversal', () => {
    it('wraps to a closed disclosure summary and skips its hidden action', () => {
        document.body.innerHTML = `<div id="dialog"><button id="close">Close</button><details><summary>Delete lead</summary><button id="delete">Delete permanently</button></details></div>`;
        const root = document.getElementById('dialog')!;
        const close = document.getElementById('close')!;
        close.focus();
        containDialogFocus(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, cancelable: true }), root);
        expect(document.activeElement?.tagName).toBe('SUMMARY');
        root.querySelector('details')!.open = true;
        close.focus();
        containDialogFocus(new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, cancelable: true }), root);
        expect(document.activeElement?.id).toBe('delete');
    });

    it('excludes hidden, inert and disabled-fieldset descendants', () => {
        document.body.innerHTML = `<div id="dialog"><button id="cancel">Cancel</button><button id="save">Save</button><div hidden><button>Hidden</button></div><div inert><button>Inert</button></div><fieldset disabled><button>Disabled</button></fieldset><div style="display:none"><button>Invisible</button></div></div>`;
        document.getElementById('cancel')!.focus();
        containDialogFocus(
            new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, cancelable: true }),
            document.getElementById('dialog'),
        );
        expect(document.activeElement?.id).toBe('save');
    });
});
