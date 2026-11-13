const unsaved = new Map();
const fieldName = (element) => element.getAttributeNames()
    .filter((name) => name.startsWith('wire:model'))
    .map((name) => element.getAttribute(name))[0] ?? element.id;
const fieldValue = (element) => element.type === 'checkbox' ? element.checked
    : element.type === 'file' ? Array.from(element.files ?? [], (file) => file.name) : element.value;
const draftRoot = () => document.querySelector?.('[data-recover-fields]');
const draftKey = (root) => `portal-draft:${root.dataset.draftUser}:${window.location.pathname}`;
const recoveryFields = (root) => root.dataset.recoverFields.split(',');
const recoverable = (root, field) => recoveryFields(root).includes(field.split('.')[0]);
const safeFields = (fields) => fields && typeof fields === 'object' && !Array.isArray(fields)
    && Object.entries(fields).every(([name, value]) => /^[a-zA-Z_][\w]*(\.[\w]+)*$/.test(name)
        && !name.split('.').some((part) => ['__proto__', 'prototype', 'constructor'].includes(part))
        && (typeof value === 'string' || typeof value === 'boolean'));
let recovery = null;
let recoveryRoot = null;

function persistDraft() {
    const root = draftRoot();
    if (!root) return;
    const fields = { ...recovery, ...Object.fromEntries([...unsaved].filter(([field, value]) => recoverable(root, field)
        && (typeof value === 'string' || typeof value === 'boolean'))) };
    if (unsaved.has('body') || unsaved.has('visibility')) {
        const body = root.querySelector('[id="body"]');
        const audience = root.querySelector('[id="visibility"]');
        fields.body = unsaved.get('body') ?? body?.value ?? '';
        if (audience) fields.visibility = unsaved.get('visibility') ?? audience.value;
        else delete fields.visibility;
    } else if (Object.hasOwn(fields, 'body')) {
        const audience = root.querySelector('[id="visibility"]');
        if (audience && !Object.hasOwn(fields, 'visibility')) fields.visibility = audience.value;
    }
    try {
        if (Object.keys(fields).length) window.sessionStorage.setItem(draftKey(root), JSON.stringify({ savedAt: Date.now(), fields }));
        else window.sessionStorage.removeItem(draftKey(root));
    } catch {
        const status = root.querySelector('[data-draft-status]');
        if (status) status.textContent = 'Хранилище браузера недоступно. Не закрывайте страницу, пока изменения не будут сохранены.';
    }
}

function initializeRecovery() {
    const root = draftRoot();
    if (!root || recoveryRoot === root) return;
    recoveryRoot = root;
    recovery = null;
    unsaved.clear();
    try {
        const stored = JSON.parse(window.sessionStorage.getItem(draftKey(root)) ?? 'null');
        if (stored && safeFields(stored.fields) && Date.now() - stored.savedAt < 12 * 60 * 60 * 1000
            && stored.savedAt <= Date.now()) {
            recovery = Object.fromEntries(Object.entries(stored.fields).filter(([field]) => recoverable(root, field)));
            const panel = root.querySelector('[data-draft-recovery]');
            if (panel) panel.hidden = !Object.keys(recovery).length;
        } else window.sessionStorage.removeItem(draftKey(root));
    } catch { /* Storage restrictions do not block normal editing. */ }
}

document.addEventListener('DOMContentLoaded', initializeRecovery);
document.addEventListener('livewire:navigated', initializeRecovery);
document.addEventListener('input', (event) => {
    if (event.target.closest('[data-draft-form]')) {
        unsaved.set(fieldName(event.target), fieldValue(event.target));
        persistDraft();
    }
});
document.addEventListener('change', (event) => {
    if (event.target.closest('[data-draft-form]')) {
        unsaved.set(fieldName(event.target), fieldValue(event.target));
        persistDraft();
    }
});
document.addEventListener('click', (event) => {
    const root = draftRoot();
    if (!root) return;
    if (event.target.closest('[data-draft-discard]')) {
        recovery = null;
        root.querySelector('[data-draft-recovery]').hidden = true;
        const status = root.querySelector('[data-draft-status]');
        if (status) status.textContent = 'Прежний черновик удалён. Новые изменения на этой странице сохранены. Ничего не отправлено.';
        persistDraft();
    }
    if (event.target.closest('[data-draft-restore]') && recovery) {
        const host = root.closest('[wire\\:id]') ?? root;
        const component = window.Livewire?.find(host.getAttribute('wire:id'));
        if (!component) return;
        const elements = Array.from(root.querySelectorAll('input,textarea,select'));
        const messageEdited = unsaved.has('body') || unsaved.has('visibility');
        const audience = elements.find((element) => fieldName(element) === 'visibility');
        const availableValue = (element, value) => element?.options
            ? Array.from(element.options).some((option) => !option.disabled && option.value === value) : true;
        const safeAudience = !recovery.visibility || (audience && !audience.disabled && availableValue(audience, recovery.visibility))
            || (!audience && recovery.visibility === 'public');
        for (const [field, value] of Object.entries(recovery)) {
            if (unsaved.has(field) || (messageEdited && ['body', 'visibility'].includes(field))) {
                delete recovery[field];
                continue;
            }
            const element = elements.find((element) => fieldName(element) === field);
            if (!element || element.disabled || ['file', 'password', 'hidden'].includes(element.type)
                || !availableValue(element, value) || (['body', 'visibility'].includes(field) && !safeAudience)) continue;
            component.$set(field, value, false);
            if (element.type === 'checkbox') element.checked = value === true;
            else element.value = value;
            unsaved.set(field, value);
            delete recovery[field];
        }
        const remaining = Object.keys(recovery).length > 0;
        root.querySelector('[data-draft-recovery]').hidden = !remaining;
        if (!remaining) recovery = null;
        const status = root.querySelector('[data-draft-status]');
        if (status) status.textContent = remaining
            ? 'Новые изменения сохранены. Часть полей черновика здесь недоступна и остаётся сохранённой; проверьте получателей сообщения перед восстановлением. Ничего не отправлено.'
            : 'Черновик восстановлен, новые изменения сохранены. Проверьте данные, получателей сообщения и выбранные файлы перед отправкой. Ничего не отправлено.';
        persistDraft();
    }
});
document.addEventListener('draft-saved', (event) => {
    for (const [field, value] of unsaved) {
        const saved = field.split('.').reduce((current, part) => current?.[part], event.detail?.values);
        if (saved !== undefined && String(saved ?? '') === String(value ?? '')) {
            unsaved.delete(field);
            if (recovery) delete recovery[field];
        }
    }
    if (recovery) {
        for (const [field, value] of Object.entries(recovery)) {
            const saved = field.split('.').reduce((current, part) => current?.[part], event.detail?.values);
            if (saved !== undefined && String(saved ?? '') === String(value ?? '')) delete recovery[field];
        }
    }
    persistDraft();
});
document.addEventListener('form-committed', () => { unsaved.clear(); recovery = null; persistDraft(); });
document.addEventListener('submit', (event) => {
    if (event.target.matches?.('[data-logout-form]')) {
        try {
            for (const key of Object.keys(window.sessionStorage)) {
                if (key.startsWith('portal-draft:')) window.sessionStorage.removeItem(key);
            }
        } catch {}
    }
});
window.addEventListener('beforeunload', (event) => {
    if (!unsaved.size) return;
    event.preventDefault();
    event.returnValue = '';
});
