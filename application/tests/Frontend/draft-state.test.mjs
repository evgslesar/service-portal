import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

function page(storage = {}, actor = '1', path = '/jobs/1') {
    const events = new Map();
    const elements = new Map();
    const changes = [];
    const panel = { hidden: true };
    const status = {};
    const root = {
        dataset: { draftUser: actor, recoverFields: 'report,reason,checks,labor_minutes,body,visibility' },
        closest: () => root,
        getAttribute: () => 'component-1',
        querySelector: (selector) => selector === '[data-draft-recovery]' ? panel : selector === '[data-draft-status]' ? status
            : elements.get(selector === '[id="body"]' ? 'body' : 'visibility'),
        querySelectorAll: () => [...elements.values()],
    };
    runInNewContext(source, {
        document: { querySelector: () => root, addEventListener: (name, callback) => events.set(name, callback) },
        window: {
            location: { pathname: path },
            sessionStorage: new Proxy({
                setItem: (key, value) => { storage[key] = value; },
                getItem: (key) => storage[key] ?? null,
                removeItem: (key) => { delete storage[key]; },
            }, { ownKeys: () => Object.keys(storage), getOwnPropertyDescriptor: () => ({ enumerable: true, configurable: true }) }),
            Livewire: { find: () => ({ $set: (...args) => changes.push(args) }) },
            addEventListener: (name, callback) => events.set(name, callback),
        },
    });
    events.get('DOMContentLoaded')();
    const element = (field, value) => ({
        id: field, type: typeof value === 'boolean' ? 'checkbox' : 'text',
        checked: value, value, closest: () => root,
        getAttributeNames: () => ['wire:model'], getAttribute: () => field,
    });
    return {
        storage, changes, panel,
        field(field, value, properties = {}) { elements.set(field, { ...element(field, value), ...properties }); },
        restore() { events.get('click')({ target: { closest: (selector) => selector === '[data-draft-restore]' } }); },
        discard() { events.get('click')({ target: { closest: (selector) => selector === '[data-draft-discard]' } }); },
        logout() { events.get('submit')({ target: { matches: () => true } }); },
        input(field, value, draft = true) {
            const target = element(field, value);
            target.closest = () => draft;
            elements.set(field, target);
            events.get('input')({ target });
        },
        saved(values) { events.get('draft-saved')({ detail: { values } }); },
        committed() { events.get('form-committed')(); },
        warns() {
            let warned = false;
            events.get('beforeunload')({ preventDefault: () => { warned = true; } });
            return warned;
        },
    };
}

test('a reload offers scoped recovery without auto-submitting or restoring operation state', () => {
    const storage = {};
    const original = page(storage);
    original.input('report', 'Offline report');
    original.input('operationKeys.finish', 'Not recoverable');
    const reloaded = page(storage);
    reloaded.field('report', 'Current server report');
    assert.equal(reloaded.panel.hidden, false);
    assert.equal(reloaded.changes.length, 0);
    reloaded.restore();
    assert.equal(JSON.stringify(reloaded.changes), JSON.stringify([['report', 'Offline report', false]]));
    assert.equal(reloaded.warns(), true);
    reloaded.saved({ report: 'Offline report' });
    assert.equal(Object.keys(storage).length, 0);
});

test('draft recovery is isolated by actor and page and cleared on logout', () => {
    const storage = {};
    page(storage).input('report', 'Private report');
    assert.equal(page(storage, '2').panel.hidden, true);
    assert.equal(page(storage, '1', '/jobs/2').panel.hidden, true);
    const original = page(storage);
    original.logout();
    assert.equal(Object.keys(storage).length, 0);
});

test('message draft preserves its audience and a final commit clears recovery', () => {
    const storage = {};
    const original = page(storage, '1', '/requests/1');
    original.field('visibility', 'internal');
    original.input('body', 'Internal details');
    const reloaded = page(storage, '1', '/requests/1');
    reloaded.field('body', '');
    reloaded.field('visibility', 'public');
    reloaded.restore();
    assert.equal(JSON.stringify(reloaded.changes), JSON.stringify([['body', 'Internal details', false], ['visibility', 'internal', false]]));
    reloaded.committed();
    assert.equal(Object.keys(storage).length, 0);
});

test('restoring an earlier draft keeps newer edits and restores other fields', () => {
    const storage = {};
    const original = page(storage);
    original.input('report', 'Earlier report');
    original.input('reason', 'Earlier help text');
    const reloaded = page(storage);
    reloaded.field('reason', '');
    reloaded.input('report', 'New report');
    reloaded.restore();
    assert.equal(JSON.stringify(reloaded.changes), JSON.stringify([['reason', 'Earlier help text', false]]));
    const fields = JSON.parse(Object.values(storage)[0]).fields;
    assert.equal(fields.report, 'New report');
    assert.equal(fields.reason, 'Earlier help text');
});

test('discarding an earlier draft keeps newer edits recoverable after another reload', () => {
    const storage = {};
    page(storage).input('report', 'Earlier report');
    const reloaded = page(storage);
    reloaded.input('report', 'New report');
    reloaded.discard();
    assert.equal(reloaded.panel.hidden, true);
    assert.equal(reloaded.warns(), true);
    const nextReload = page(storage);
    nextReload.field('report', 'Server report');
    nextReload.restore();
    assert.equal(JSON.stringify(nextReload.changes), JSON.stringify([['report', 'New report', false]]));
});

test('new message text keeps its current audience instead of the earlier draft audience', () => {
    const storage = {};
    const original = page(storage, '1', '/requests/1');
    original.field('visibility', 'internal');
    original.input('body', 'Earlier internal text');
    const reloaded = page(storage, '1', '/requests/1');
    reloaded.field('visibility', 'public');
    reloaded.input('body', 'New public text');
    const fields = JSON.parse(Object.values(storage)[0]).fields;
    assert.equal(fields.body, 'New public text');
    assert.equal(fields.visibility, 'public');
    reloaded.restore();
    assert.equal(reloaded.changes.length, 0);
    assert.equal(reloaded.warns(), true);
});

test('editing audience alone never couples an earlier internal body to a new public audience', () => {
    const storage = {};
    const original = page(storage, '1', '/requests/1');
    original.field('visibility', 'internal');
    original.input('body', 'Earlier internal text');
    const reloaded = page(storage, '1', '/requests/1');
    reloaded.field('body', '');
    reloaded.input('visibility', 'public');
    const fields = JSON.parse(Object.values(storage)[0]).fields;
    assert.equal(fields.body, '');
    assert.equal(fields.visibility, 'public');
    const nextReload = page(storage, '1', '/requests/1');
    nextReload.field('body', '');
    nextReload.field('visibility', 'public');
    nextReload.restore();
    assert.equal(nextReload.changes.some(([field, value]) => field === 'body' && value === 'Earlier internal text'), false);
});

test('an internal message stays recoverable when its audience control is unavailable', () => {
    const storage = {};
    const original = page(storage, '1', '/requests/1');
    original.field('visibility', 'internal');
    original.input('body', 'Internal text');
    const reloaded = page(storage, '1', '/requests/1');
    reloaded.field('body', '');
    reloaded.restore();
    assert.equal(reloaded.changes.length, 0);
    assert.equal(reloaded.panel.hidden, false);
    assert.equal(JSON.parse(Object.values(storage)[0]).fields.body, 'Internal text');
    reloaded.field('visibility', 'public', { options: [{ value: 'public' }, { value: 'internal' }] });
    reloaded.restore();
    assert.equal(JSON.stringify(reloaded.changes), JSON.stringify([['body', 'Internal text', false], ['visibility', 'internal', false]]));
    assert.equal(reloaded.panel.hidden, true);
});

test('an unavailable audience option cannot restore an internal message as public', () => {
    const storage = {};
    const original = page(storage, '1', '/requests/1');
    original.field('visibility', 'internal');
    original.input('body', 'Internal text');
    const reloaded = page(storage, '1', '/requests/1');
    reloaded.field('body', '');
    reloaded.field('visibility', 'public', { options: [{ value: 'public' }] });
    reloaded.restore();
    assert.equal(reloaded.changes.length, 0);
    assert.equal(reloaded.panel.hidden, false);
});

test('expired or corrupt drafts are not restored', () => {
    const key = 'portal-draft:1:/jobs/1';
    const expired = { [key]: JSON.stringify({ savedAt: 0, fields: { report: 'Old' } }) };
    assert.equal(page(expired).panel.hidden, true);
    assert.equal(Object.keys(expired).length, 0);
    const corrupt = { [key]: JSON.stringify({ savedAt: Date.now(), fields: { 'report.__proto__': 'Unsafe' } }) };
    assert.equal(page(corrupt).panel.hidden, true);
});

test('saving a report does not clear an unsent help message', () => {
    const form = page();
    form.input('report', 'Completed check');
    form.input('reason', 'Need access');
    form.saved({ report: 'Completed check' });
    assert.equal(form.warns(), true);
    form.saved({ reason: 'Need access' });
    assert.equal(form.warns(), false);
});

test('an older save response does not clear newer typed text', () => {
    const form = page();
    form.input('report', 'Newer report');
    form.saved({ report: 'Older report' });
    assert.equal(form.warns(), true);
    form.saved({ report: 'Newer report' });
    assert.equal(form.warns(), false);
});

test('checkboxes and numeric inputs match their saved values', () => {
    const form = page();
    form.input('checks.0', true);
    form.input('labor_minutes', '20');
    form.saved({ checks: [true], labor_minutes: 20 });
    assert.equal(form.warns(), false);
});

test('only a final commit clears all unsent fields', () => {
    const form = page();
    form.input('search', 'Not a draft', false);
    assert.equal(form.warns(), false);
    form.input('reason', 'Unsent');
    form.saved({ report: 'Unrelated saved draft' });
    assert.equal(form.warns(), true);
    form.committed();
    assert.equal(form.warns(), false);
});
