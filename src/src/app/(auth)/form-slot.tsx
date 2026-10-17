"use client";

import { useCallback } from "react";

/**
 * What a person had typed into the auth page's fields, taken as one layout
 * leaves the page and given to the next.
 */
type Carried = {
  page: string;
  values: Map<string, string>;
  focused: { key: string; start: number | null; end: number | null } | null;
};

// Only fields a person types into. A checkbox or a hidden input is the form's
// own state, and the form sets it again when it mounts.
const NOT_TYPED = new Set([
  "hidden",
  "file",
  "checkbox",
  "radio",
  "submit",
  "button",
  "reset",
  "image",
]);

type Field = HTMLInputElement | HTMLTextAreaElement;

function fieldKey(field: Field): string {
  return field.name || field.id;
}

function typedFields(root: HTMLElement): Field[] {
  return Array.from(root.querySelectorAll<Field>("input, textarea")).filter(
    (field) =>
      fieldKey(field) !== "" &&
      !(field instanceof HTMLInputElement && NOT_TYPED.has(field.type)),
  );
}

function take(root: HTMLElement, page: string): Carried {
  const values = new Map<string, string>();
  for (const field of typedFields(root)) {
    if (field.value !== "") values.set(fieldKey(field), field.value);
  }

  let focused: Carried["focused"] = null;
  const active = document.activeElement;
  if (
    (active instanceof HTMLInputElement ||
      active instanceof HTMLTextAreaElement) &&
    root.contains(active) &&
    fieldKey(active) !== ""
  ) {
    // An email input has no selection to read; it reports null.
    let start: number | null = null;
    let end: number | null = null;
    try {
      start = active.selectionStart;
      end = active.selectionEnd;
    } catch {
      // Inputs without a text selection throw on some engines.
    }
    focused = { key: fieldKey(active), start, end };
  }

  return { page, values, focused };
}

function give(root: HTMLElement, carried: Carried) {
  for (const field of typedFields(root)) {
    const value = carried.values.get(fieldKey(field));
    // What the new form set itself (an organisation from the context, an
    // email from the hand-off) stands.
    if (value === undefined || field.value !== "") continue;

    // Through the element's own setter, then an input event, so the form's
    // state takes the value exactly as if it had been typed: React only
    // reports a change it did not set itself.
    const setter = Object.getOwnPropertyDescriptor(
      Object.getPrototypeOf(field),
      "value",
    )?.set;
    setter?.call(field, value);
    field.dispatchEvent(new Event("input", { bubbles: true }));
  }

  const focused = carried.focused;
  if (!focused) return;
  const field = typedFields(root).find((f) => fieldKey(f) === focused.key);
  if (!field) return;
  field.focus();
  if (focused.start !== null && focused.end !== null) {
    try {
      field.setSelectionRange(focused.start, focused.end);
    } catch {
      // Not every input type has a text selection to set.
    }
  }
}

/**
 * The place an auth page's form sits in either layout.
 *
 * On the organisation's front door the layout changes once its context
 * arrives: the two-panel sign-in becomes the organisation portal. That is a
 * different element tree, so React mounts the form again, and a fresh form
 * is an empty one. React keeps state only for an element of the same type at
 * the same position, and there is no way to move one into another parent;
 * a portal would, but it cannot be server rendered, and this page is.
 *
 * So the slot carries the fields across instead. As the old layout's slot
 * leaves the page (its ref cleanup runs before React removes the nodes) it
 * takes what was typed and which field had focus; the new layout's slot,
 * attached in the same commit and after the form inside it, gives them back.
 * Only within one page: a navigation that also changes the layout starts its
 * new page empty. And only within that commit: whatever was not given back
 * is dropped.
 *
 * The wrapper is `display: contents`, so neither layout looks any different,
 * and it renders the same on the server as on the client: the carrying is
 * done in a ref, after hydration, never during render (React #418).
 */
export function FormSlot({
  page,
  children,
}: {
  page: string;
  children: React.ReactNode;
}) {
  return (
    <div ref={useCarry(page)} className="contents">
      {children}
    </div>
  );
}

// The slot leaving and the slot arriving are different component instances,
// so what passes between them is held here. One document has one auth
// layout, and nothing is held past the commit that took it.
let carry: Carried | null = null;

function useCarry(page: string) {
  return useCallback(
    (node: HTMLDivElement | null) => {
      if (!node) return;
      const carried = carry;
      carry = null;
      if (carried && carried.page === page) give(node, carried);

      return () => {
        const taken = take(node, page);
        carry = taken;
        // The arriving slot's ref is attached in the same task as this
        // removal, before any microtask, so it has already taken this.
        queueMicrotask(() => {
          if (carry === taken) carry = null;
        });
      };
    },
    [page],
  );
}
