"use client";

import { FormField } from "@/components/patterns/FormField";
import { ReferenceSelect } from "@/components/shared/reference-select";
import { Input } from "@/components/ui/input";
import {
  referenceName,
  useLookups,
  type ReferenceKind,
} from "@/features/reference/api";
import { useT } from "@/lib/i18n/useT";

/** The code of the Addis Ababa city administration in the shared region list. */
const ADDIS_ABABA = "addis_ababa";

/**
 * An Ethiopian address as the form holds it. Codes are `null` when the person
 * typed the place instead of picking it; text is `""` when empty, so an input
 * is never uncontrolled.
 */
export interface AddressFields {
  address_region_code: string | null;
  address_region_text: string;
  address_sub_city_code: string | null;
  address_zone_or_sub_city_text: string;
  address_town_code: string | null;
  address_town_text: string;
  address_woreda: string;
  address_kebele: string;
  address_house_number: string;
}

export const EMPTY_ADDRESS: AddressFields = {
  address_region_code: null,
  address_region_text: "",
  address_sub_city_code: null,
  address_zone_or_sub_city_text: "",
  address_town_code: null,
  address_town_text: "",
  address_woreda: "",
  address_kebele: "",
  address_house_number: "",
};

/** A saved record, or nothing, as the form's value. */
export function addressFromRecord(
  record:
    Partial<Record<keyof AddressFields, string | null>> | null | undefined,
): AddressFields {
  return {
    address_region_code: record?.address_region_code ?? null,
    address_region_text: record?.address_region_text ?? "",
    address_sub_city_code: record?.address_sub_city_code ?? null,
    address_zone_or_sub_city_text: record?.address_zone_or_sub_city_text ?? "",
    address_town_code: record?.address_town_code ?? null,
    address_town_text: record?.address_town_text ?? "",
    address_woreda: record?.address_woreda ?? "",
    address_kebele: record?.address_kebele ?? "",
    address_house_number: record?.address_house_number ?? "",
  };
}

/**
 * What goes to the server. Empty text is sent as `null`, so a cleared box
 * clears the column, and a code never goes without its text.
 */
export function addressToPayload(value: AddressFields): {
  [K in keyof AddressFields]: string | null;
} {
  const text = (s: string) => (s.trim() === "" ? null : s.trim());
  const pair = (code: string | null, name: string) => {
    const t = text(name);
    return { code: t === null ? null : code, text: t };
  };
  const region = pair(value.address_region_code, value.address_region_text);
  const sub = pair(
    value.address_sub_city_code,
    value.address_zone_or_sub_city_text,
  );
  const town = pair(value.address_town_code, value.address_town_text);

  return {
    address_region_code: region.code,
    address_region_text: region.text,
    address_sub_city_code: sub.code,
    address_zone_or_sub_city_text: sub.text,
    address_town_code: town.code,
    address_town_text: town.text,
    address_woreda: text(value.address_woreda),
    address_kebele: text(value.address_kebele),
    address_house_number: text(value.address_house_number),
  };
}

/**
 * An address as one line, smallest place first: house, kebele, woreda, then the
 * sub-city or zone, town and region. A place picked from a list reads in the
 * reader's language, and a name typed, or older than the list, reads as it was
 * saved. Empty when there is nothing to show.
 */
export function useFormatAddress() {
  const { t, locale } = useT();
  const lookups = useLookups();

  return (
    record:
      Partial<Record<keyof AddressFields, string | null>> | null | undefined,
  ): string => {
    if (!record) return "";

    const place = (
      kind: ReferenceKind,
      code: string | null | undefined,
      text: string | null | undefined,
    ) => {
      const hit = code
        ? lookups.data?.items?.[kind]?.find((i) => i.code === code)
        : undefined;
      return hit ? referenceName(hit, locale) : (text ?? "").trim();
    };
    const numbered = (key: string, fallback: string, v?: string | null) =>
      v && v.trim() !== "" ? t(key, fallback, { n: v.trim() }) : "";

    return [
      numbered(
        "address.house_number",
        "House number",
        record.address_house_number,
      ),
      numbered("address.kebele_n", "Kebele :n", record.address_kebele),
      numbered("address.woreda_n", "Woreda :n", record.address_woreda),
      place(
        "sub_city",
        record.address_sub_city_code,
        record.address_zone_or_sub_city_text,
      ),
      place("town", record.address_town_code, record.address_town_text),
      place("region", record.address_region_code, record.address_region_text),
    ]
      .filter((part) => part !== "")
      .join(", ");
  };
}

interface AddressFormProps {
  value: AddressFields;
  onChange: (next: AddressFields) => void;
  disabled?: boolean;
  /** Prefix for the field ids, so a page with two addresses keeps them apart. */
  idPrefix?: string;
  errors?: Partial<Record<keyof AddressFields, string>>;
}

/**
 * Where a person lives, from the shared lists.
 *
 * The region comes first and shapes the rest. In Addis Ababa the next field is
 * the sub-city; anywhere else it is a zone, typed, and then the town. Woreda,
 * kebele and house number are typed, as they are not on any list.
 *
 * Every pick can be cleared, because people move, and every list falls back to
 * typed text as `ReferenceSelect` does. Changing the region clears the
 * sub-city, zone and town that belonged to the old one.
 */
export function AddressForm({
  value,
  onChange,
  disabled,
  idPrefix = "address",
  errors,
}: AddressFormProps) {
  const { t } = useT();
  const set = (patch: Partial<AddressFields>) =>
    onChange({ ...value, ...patch });
  const inAddis = value.address_region_code === ADDIS_ABABA;
  const region = value.address_region_code;

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      <FormField
        id={`${idPrefix}_region`}
        label={t("address.region", "Region")}
        error={errors?.address_region_code ?? errors?.address_region_text}
      >
        {(control) => (
          <ReferenceSelect
            {...control}
            kind="region"
            clearable
            disabled={disabled}
            className="mt-1"
            value={{
              code: value.address_region_code,
              text: value.address_region_text,
            }}
            onChange={(next) =>
              next.code === value.address_region_code
                ? set({
                    address_region_code: next.code,
                    address_region_text: next.text,
                  })
                : set({
                    address_region_code: next.code,
                    address_region_text: next.text,
                    address_sub_city_code: null,
                    address_zone_or_sub_city_text: "",
                    address_town_code: null,
                    address_town_text: "",
                  })
            }
          />
        )}
      </FormField>

      {inAddis ? (
        <FormField
          id={`${idPrefix}_sub_city`}
          label={t("address.sub_city", "Sub-city")}
          error={
            errors?.address_sub_city_code ??
            errors?.address_zone_or_sub_city_text
          }
        >
          {(control) => (
            <ReferenceSelect
              {...control}
              kind="sub_city"
              parentCode={ADDIS_ABABA}
              clearable
              disabled={disabled}
              className="mt-1"
              value={{
                code: value.address_sub_city_code,
                text: value.address_zone_or_sub_city_text,
              }}
              onChange={(next) =>
                set({
                  address_sub_city_code: next.code,
                  address_zone_or_sub_city_text: next.text,
                })
              }
            />
          )}
        </FormField>
      ) : (
        <>
          <FormField
            id={`${idPrefix}_zone`}
            label={t("address.zone", "Zone")}
            error={errors?.address_zone_or_sub_city_text}
          >
            <Input
              value={value.address_zone_or_sub_city_text}
              disabled={disabled}
              maxLength={100}
              className="mt-1"
              onChange={(e) =>
                set({
                  address_sub_city_code: null,
                  address_zone_or_sub_city_text: e.target.value,
                })
              }
            />
          </FormField>
          <FormField
            id={`${idPrefix}_town`}
            label={t("address.town", "Town or city")}
            error={errors?.address_town_code ?? errors?.address_town_text}
          >
            {region === null ? (
              <Input
                value={value.address_town_text}
                disabled={disabled}
                maxLength={100}
                className="mt-1"
                onChange={(e) =>
                  set({
                    address_town_code: null,
                    address_town_text: e.target.value,
                  })
                }
              />
            ) : (
              (control) => (
                <ReferenceSelect
                  {...control}
                  // A town typed under one region must not stay a text box
                  // when the region changes: the new region has its own list.
                  key={region}
                  kind="town"
                  parentCode={region}
                  clearable
                  disabled={disabled}
                  className="mt-1"
                  value={{
                    code: value.address_town_code,
                    text: value.address_town_text,
                  }}
                  onChange={(next) =>
                    set({
                      address_town_code: next.code,
                      address_town_text: next.text,
                    })
                  }
                />
              )
            )}
          </FormField>
        </>
      )}

      <FormField
        id={`${idPrefix}_woreda`}
        label={t("address.woreda", "Woreda")}
        error={errors?.address_woreda}
      >
        <Input
          value={value.address_woreda}
          disabled={disabled}
          maxLength={50}
          className="mt-1"
          onChange={(e) => set({ address_woreda: e.target.value })}
        />
      </FormField>
      <FormField
        id={`${idPrefix}_kebele`}
        label={t("address.kebele", "Kebele")}
        error={errors?.address_kebele}
      >
        <Input
          value={value.address_kebele}
          disabled={disabled}
          maxLength={50}
          className="mt-1"
          onChange={(e) => set({ address_kebele: e.target.value })}
        />
      </FormField>
      <FormField
        id={`${idPrefix}_house_number`}
        label={t("address.house_number", "House number")}
        error={errors?.address_house_number}
      >
        <Input
          value={value.address_house_number}
          disabled={disabled}
          maxLength={50}
          className="mt-1"
          onChange={(e) => set({ address_house_number: e.target.value })}
        />
      </FormField>
    </div>
  );
}
