"use client";

import { useState } from "react";
import Link from "next/link";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useCreateDevice,
  useDevice,
  useSendStaffToDevice,
  type Device,
} from "@/features/devices/api";
import { AdmsSetupValues } from "@/features/devices/components/adms-setup-values";
import { AllowAddressButton } from "@/features/devices/components/allow-address-button";
import { CopyValueButton } from "@/features/devices/components/copy-value-button";
import { WebhookRejectedLine } from "@/features/devices/components/device-heard";
import {
  DeviceConnectionSection,
  DeviceIdentitySection,
  DevicePeopleSection,
  useDeviceFormState,
  type Branch,
} from "@/features/devices/components/device-form";
import { PullConnectionTest } from "@/features/devices/components/pull-connection-test";
import { PushSetup } from "@/features/devices/components/push-setup";
import {
  firstContactFor,
  isConfirmed,
  type FirstContact,
} from "@/features/devices/first-contact";
import {
  issuedSetupFor,
  type IssuedSetup,
} from "@/features/devices/issued-setup";
import {
  buildDevicePayload,
  wizardChoice,
  type ConnectionMode,
  type WizardBrand,
  type WizardReach,
} from "@/features/devices/payload";
import { apiErrorMessage } from "@/lib/api/error-message";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/** How often the first-contact step asks the server what it has heard. */
export const FIRST_CONTACT_POLL_MS = 5000;

type Step = "choose" | "details" | "terminal" | "waiting" | "done";
const STEPS: Step[] = ["choose", "details", "terminal", "waiting", "done"];

const BRANDS: WizardBrand[] = ["hikvision", "zkteco", "suprema"];
const BRAND_LABELS: Record<WizardBrand, string> = {
  hikvision: "Hikvision",
  zkteco: "ZKTeco",
  suprema: "Suprema",
};

/** The device just registered, and what the wizard keeps of its one-time setup. */
interface Registered {
  device: Device;
  setup: IssuedSetup | null;
  mode: ConnectionMode;
  ip: string;
  port: string;
}

interface DeviceSetupWizardProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Undefined while the list is still loading: Register waits for it. */
  branches: Branch[] | undefined;
  /** The full form, for people who know the values. Nothing has been registered yet. */
  onAdvanced: () => void;
  /** Open the enrolled-users dialog for the new device; the wizard has closed. */
  onDiscover: (device: Device) => void;
}

/**
 * A guided setup for a new terminal: two plain questions choose the adapter
 * and how it connects, the details step reuses the device form's own sections,
 * and the wizard then shows what to type on the terminal and waits, polling
 * the device, for the first contact. «Advanced» opens the full form.
 */
export function DeviceSetupWizard(props: DeviceSetupWizardProps) {
  return (
    <Dialog open={props.open} onOpenChange={props.onOpenChange}>
      {props.open && <WizardContent {...props} />}
    </Dialog>
  );
}

function WizardContent({
  onOpenChange,
  branches,
  onAdvanced,
  onDiscover,
}: DeviceSetupWizardProps) {
  const { t } = useT();
  const [step, setStep] = useState<Step>("choose");
  const [brand, setBrand] = useState<WizardBrand | null>(null);
  const [reach, setReach] = useState<WizardReach | null>(null);
  const [registered, setRegistered] = useState<Registered | null>(null);
  const [confirmLeave, setConfirmLeave] = useState(false);
  // Register waits for the branch list. Before it arrives the form neither
  // draws the field nor fills in an only branch, so a quick click sent no
  // branch and was refused (the e2e wizard journey found it).
  const branchesLoading = branches === undefined;
  const branchList = branches ?? [];
  const state = useDeviceFormState("create", undefined, branchList);
  const create = useCreateDevice();

  const choice = brand && reach ? wizardChoice(brand, reach) : null;
  const answered = brand !== null && reach !== null;
  // The address of a push terminal is shown once, so nothing closes the dialog
  // by accident while it may still be on screen.
  const oneTime =
    registered?.setup?.mode === "push" &&
    (step === "terminal" || step === "waiting");
  // Nor while Register is on its way: closing then would unmount the wizard
  // before the answer arrives, losing that address, and Advanced would offer
  // to register the same terminal a second time.
  const locked = oneTime || create.isPending;

  function goTo(next: Step) {
    setConfirmLeave(false);
    setStep(next);
  }

  function chooseNext() {
    if (!brand || !choice) return;
    state.patch({
      adapter_type: choice.adapter_type,
      sends_to_ethr: choice.sends_to_ethr,
      port: brand === "zkteco" ? "4370" : "80",
    });
    goTo("details");
  }

  function register(e: React.FormEvent) {
    e.preventDefault();
    if (state.connection === "adms" && state.form.serial_number.trim() === "") {
      toast.error(t("device_wizard.serial_needed"));
      return;
    }
    if (branchesLoading || state.branchMissing) return;

    create.mutate(buildDevicePayload(state.form, "create"), {
      onSuccess: (device) => {
        toast.success(t("devices_page.registered"));
        setRegistered({
          device,
          setup: issuedSetupFor(device),
          // The server decides the mode; the form only predicted it.
          mode: device.connection_mode ?? state.connection,
          ip: state.form.ip.trim(),
          port: state.form.port,
        });
        goTo("terminal");
      },
      onError: (err: unknown) =>
        toast.error(apiErrorMessage(err, t("devices_page.add_failed"))),
    });
  }

  function finishLater() {
    if (!registered) return;
    if (oneTime && !confirmLeave) {
      setConfirmLeave(true);
      return;
    }
    toast.info(
      t("device_wizard.later_saved", undefined, {
        name: registered.device.name,
      }),
    );
    onOpenChange(false);
  }

  const index = STEPS.indexOf(step);
  const title: Record<Step, string> = {
    choose: t("device_wizard.title"),
    details: t("device_wizard.details_title"),
    terminal: t("device_wizard.terminal_title"),
    waiting: t("device_wizard.waiting_title"),
    done: t("device_wizard.done_title"),
  };

  return (
    <DialogContent
      className={`max-h-[90vh] max-w-lg overflow-y-auto${locked ? " [&>button]:hidden" : ""}`}
      onInteractOutside={locked ? (e) => e.preventDefault() : undefined}
      onEscapeKeyDown={locked ? (e) => e.preventDefault() : undefined}
    >
      <DialogHeader>
        <p className="text-xs text-muted-foreground">
          {t("device_wizard.step_of", undefined, {
            n: index + 1,
            total: STEPS.length,
          })}
        </p>
        <DialogTitle>{title[step]}</DialogTitle>
        <DialogDescription className="sr-only">
          {t("device_wizard.advanced_hint")}
        </DialogDescription>
      </DialogHeader>

      {step === "choose" && (
        <>
          <ChooseStep
            brand={brand}
            reach={reach}
            onBrand={setBrand}
            onReach={setReach}
            unsupported={answered && choice === null}
          />
          <DialogFooter className="sm:justify-between">
            <Button type="button" variant="ghost" onClick={onAdvanced}>
              {t("device_wizard.advanced")}
            </Button>
            <Button type="button" disabled={!choice} onClick={chooseNext}>
              {t("device_wizard.next")}
            </Button>
          </DialogFooter>
        </>
      )}

      {step === "details" && (
        <form onSubmit={register} className="space-y-4">
          <p className="text-sm text-muted-foreground">
            {t(`device_wizard.mode_${state.connection}`)}
          </p>
          <DeviceIdentitySection
            state={state}
            branches={branchList}
            lockAdapter
          />
          {state.connection === "pull" && (
            <DeviceConnectionSection state={state} hideReach />
          )}
          <DevicePeopleSection state={state} />
          <DialogFooter className="sm:justify-between">
            <Button
              type="button"
              variant="ghost"
              disabled={create.isPending}
              onClick={onAdvanced}
            >
              {t("device_wizard.advanced")}
            </Button>
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                disabled={create.isPending}
                onClick={() => goTo("choose")}
              >
                {t("device_wizard.back")}
              </Button>
              <Button
                type="submit"
                disabled={
                  create.isPending || branchesLoading || state.branchMissing
                }
              >
                {create.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("device_wizard.register_continue")}
              </Button>
            </div>
          </DialogFooter>
        </form>
      )}

      {registered && step === "terminal" && (
        <>
          <TerminalStep registered={registered} />
          {confirmLeave ? (
            <ConfirmLeave
              onStay={() => setConfirmLeave(false)}
              onLeave={finishLater}
            />
          ) : (
            <DialogFooter className="sm:justify-between">
              <Button type="button" variant="ghost" onClick={finishLater}>
                {t("device_wizard.finish_later")}
              </Button>
              <Button type="button" onClick={() => goTo("waiting")}>
                {t("device_wizard.check_connection")}
              </Button>
            </DialogFooter>
          )}
        </>
      )}

      {registered && step === "waiting" && (
        <>
          <FirstContactStep
            registered={registered}
            onBack={() => goTo("terminal")}
            onConfirmed={() => goTo("done")}
          />
          {confirmLeave ? (
            <ConfirmLeave
              onStay={() => setConfirmLeave(false)}
              onLeave={finishLater}
            />
          ) : (
            <DialogFooter className="sm:justify-start">
              <Button type="button" variant="ghost" onClick={finishLater}>
                {t("device_wizard.finish_later")}
              </Button>
            </DialogFooter>
          )}
        </>
      )}

      {registered && step === "done" && (
        <DoneStep
          registered={registered}
          onClose={() => onOpenChange(false)}
          onDiscover={(device) => {
            onOpenChange(false);
            onDiscover(device);
          }}
        />
      )}
    </DialogContent>
  );
}

/**
 * Leaving a push terminal half set up loses its address for good, so Finish
 * later asks first. It takes the footer's place, where the button was, and
 * Stay takes the focus, so a keyboard or screen reader user lands on it.
 */
function ConfirmLeave({
  onStay,
  onLeave,
}: {
  onStay: () => void;
  onLeave: () => void;
}) {
  const { t } = useT();

  return (
    <div
      role="alertdialog"
      aria-labelledby="wizard-leave-title"
      aria-describedby="wizard-leave-body"
      className="space-y-3 rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
    >
      <p id="wizard-leave-title" className="font-medium">
        {t("device_wizard.later_push_title")}
      </p>
      <p id="wizard-leave-body">{t("device_wizard.later_push_body")}</p>
      <div className="flex gap-2">
        <Button
          type="button"
          size="sm"
          variant="outline"
          autoFocus
          onClick={onStay}
        >
          {t("device_wizard.later_stay")}
        </Button>
        <Button type="button" size="sm" onClick={onLeave}>
          {t("device_wizard.later_leave")}
        </Button>
      </div>
    </div>
  );
}

function ChooseStep({
  brand,
  reach,
  onBrand,
  onReach,
  unsupported,
}: {
  brand: WizardBrand | null;
  reach: WizardReach | null;
  onBrand: (brand: WizardBrand) => void;
  onReach: (reach: WizardReach) => void;
  unsupported: boolean;
}) {
  const { t } = useT();

  return (
    <div className="space-y-5">
      <Question legend={t("device_wizard.q_brand")}>
        {BRANDS.map((value) => (
          <Choice
            key={value}
            name="device-brand"
            checked={brand === value}
            onSelect={() => onBrand(value)}
            label={BRAND_LABELS[value]}
          />
        ))}
      </Question>
      <Question legend={t("device_wizard.q_reach")}>
        <Choice
          name="device-reach"
          checked={reach === "public"}
          onSelect={() => onReach("public")}
          label={t("device_wizard.reach_public")}
          hint={t("device_wizard.reach_public_hint")}
        />
        <Choice
          name="device-reach"
          checked={reach === "office"}
          onSelect={() => onReach("office")}
          label={t("device_wizard.reach_office")}
          hint={t("device_wizard.reach_office_hint")}
        />
      </Question>
      {unsupported && (
        <p
          role="status"
          className="rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
        >
          {t("device_wizard.no_suprema_office")}
        </p>
      )}
    </div>
  );
}

function Question({
  legend,
  children,
}: {
  legend: string;
  children: React.ReactNode;
}) {
  return (
    <fieldset className="space-y-2">
      <legend className="text-sm font-medium">{legend}</legend>
      <div className="grid gap-2">{children}</div>
    </fieldset>
  );
}

function Choice({
  name,
  checked,
  onSelect,
  label,
  hint,
}: {
  name: string;
  checked: boolean;
  onSelect: () => void;
  label: string;
  hint?: string;
}) {
  return (
    <label
      className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm ${
        checked ? "border-primary bg-muted" : "hover:bg-muted/50"
      }`}
    >
      <input
        type="radio"
        name={name}
        checked={checked}
        onChange={onSelect}
        className="mt-1"
      />
      <span>
        <span className="block font-medium">{label}</span>
        {hint && (
          <span className="block text-xs text-muted-foreground">{hint}</span>
        )}
      </span>
    </label>
  );
}

/** Numbered steps for this brand and mode, with the values to type. */
function TerminalStep({ registered }: { registered: Registered }) {
  const { t } = useT();
  const { setup, device } = registered;

  const steps: React.ReactNode[] = [];

  if (setup?.mode === "adms") {
    steps.push(
      t("device_wizard.adms_step_open"),
      <div key="enter" className="space-y-2">
        <p>{t("device_wizard.adms_step_enter")}</p>
        <div data-testid="adms-setup" className="rounded-lg border p-3">
          <AdmsSetupValues
            server={setup.server}
            serial={setup.serial}
            copyable
          />
        </div>
        {setup.allowlistEmpty && (
          <p className="text-xs text-muted-foreground">
            {t("device_wizard.adms_allowlist_note")}
          </p>
        )}
      </div>,
      t("device_wizard.adms_step_save"),
    );
  } else if (setup?.mode === "push") {
    steps.push(
      t("device_wizard.push_step_open"),
      <div key="enter" className="space-y-2">
        <p>{t("device_wizard.push_step_enter")}</p>
        <PushSetup url={setup.url} copyable showIntro={false} />
        <div className="space-y-1">
          <p className="text-xs text-muted-foreground">
            {t("device_wizard.push_full_address")}
          </p>
          <div className="flex items-start gap-2">
            <code
              data-testid="issued-address"
              className="flex-1 rounded border bg-muted/50 px-3 py-2 font-mono text-xs break-all"
            >
              {setup.url}
            </code>
            <CopyValueButton
              value={setup.url}
              label={t("device_wizard.push_full_address")}
            />
          </div>
        </div>
        <p className="text-xs text-muted-foreground">
          {t("device_wizard.push_once")}
        </p>
      </div>,
      t("device_wizard.push_step_save"),
    );
  } else if (setup?.mode === "pull") {
    steps.push(
      t("device_wizard.pull_step_call", undefined, {
        ip: registered.ip,
        port: registered.port,
      }),
      t("device_wizard.pull_step_port", undefined, { port: registered.port }),
      t("device_wizard.pull_step_test"),
    );
  }

  return (
    <div className="space-y-3">
      <p className="text-sm text-muted-foreground">
        {t("device_wizard.terminal_saved", undefined, { name: device.name })}
      </p>
      <ol className="list-decimal space-y-3 pl-5 text-sm marker:text-muted-foreground">
        {steps.map((item, i) => (
          <li key={i}>{item}</li>
        ))}
      </ol>
    </div>
  );
}

/**
 * Polls the device every five seconds while it is on screen. The query is
 * owned by this component, so leaving the step, closing the dialog or
 * unmounting stops the polling with it. A pulled terminal is not polled: ETHR
 * can only learn about it by calling it, which is the test button.
 */
function FirstContactStep({
  registered,
  onBack,
  onConfirmed,
}: {
  registered: Registered;
  onBack: () => void;
  onConfirmed: () => void;
}) {
  const { t } = useT();
  const { mode } = registered;
  const query = useDevice(registered.device.public_id, {
    refetchInterval: mode === "pull" ? false : FIRST_CONTACT_POLL_MS,
  });
  const [online, setOnline] = useState(false);

  // Once the device has been read, one failed poll keeps what is on screen:
  // the next poll is five seconds away, and an error card would hide the
  // values and the Allow button while it waits.
  if (query.data) return body(query.data);

  return (
    <QueryBoundary
      query={query}
      loading={<Skeleton className="h-24 w-full" />}
      isEmpty={() => false}
    >
      {body}
    </QueryBoundary>
  );

  function body(device: Device) {
    const contact = firstContactFor(device, mode);
    const confirmed = mode === "pull" ? online : isConfirmed(contact);

    return (
      <div className="space-y-4">
        {mode === "pull" ? (
          <div className="space-y-3">
            <p role="status" className="text-sm">
              {online
                ? t("device_wizard.pull_online")
                : t("device_wizard.pull_waiting")}
            </p>
            <PullConnectionTest
              devicePublicId={device.public_id}
              adapterType={device.adapter_type}
              onResult={setOnline}
            />
          </div>
        ) : (
          <ContactStatus contact={contact} device={device} mode={mode} />
        )}
        <div className="flex flex-wrap justify-between gap-2">
          <Button type="button" variant="outline" onClick={onBack}>
            {t("device_wizard.back_to_terminal")}
          </Button>
          <Button type="button" disabled={!confirmed} onClick={onConfirmed}>
            {t("device_wizard.next")}
          </Button>
        </div>
      </div>
    );
  }
}

function ContactStatus({
  contact,
  device,
  mode,
}: {
  contact: FirstContact;
  device: Device;
  mode: ConnectionMode;
}) {
  const { t } = useT();
  const { timeAgo } = useDateFormatters();

  if (contact.kind === "refused") {
    return (
      <div
        role="status"
        className="space-y-2 rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
      >
        <p>
          {t("device_wizard.adms_refused", undefined, {
            ip: contact.ip ?? "?",
            when: timeAgo(contact.at),
          })}
        </p>
        <AllowAddressButton devicePublicId={device.public_id} ip={contact.ip} />
      </div>
    );
  }

  if (contact.kind === "heard") {
    return (
      <p
        role="status"
        className="rounded-lg border border-success-edge bg-success-soft p-3 text-sm text-success-on-soft"
      >
        {t("device_wizard.adms_heard", undefined, {
          time: contact.at ? timeAgo(contact.at) : "",
        })}
      </p>
    );
  }

  if (contact.kind === "punch") {
    return (
      <div
        role="status"
        className={`space-y-1 rounded-lg border p-3 text-sm ${
          contact.matched || (!contact.held && !contact.quarantined)
            ? "border-success-edge bg-success-soft text-success-on-soft"
            : "border-warning-edge bg-warning-soft text-warning-on-soft"
        }`}
      >
        <p className="font-medium">{t("device_wizard.push_arrived")}</p>
        <p>
          {contact.matched
            ? t("device_wizard.push_matched")
            : contact.held
              ? t("device_wizard.push_held")
              : contact.quarantined
                ? t("device_wizard.push_quarantined")
                : t("device_wizard.push_processing")}
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <p role="status" className="flex items-center gap-2 text-sm">
        <Loader2
          className="h-4 w-4 shrink-0 animate-spin text-muted-foreground"
          aria-hidden="true"
        />
        {mode === "adms"
          ? t("device_wizard.adms_waiting")
          : t("device_wizard.push_waiting")}
      </p>
      {mode === "push" && (
        <WebhookRejectedLine
          rejected={device.webhook_rejected}
          devicePublicId={device.public_id}
        />
      )}
    </div>
  );
}

/** What can be done next, by what the terminal supports (as the device actions menu offers it). */
function DoneStep({
  registered,
  onClose,
  onDiscover,
}: {
  registered: Registered;
  onClose: () => void;
  onDiscover: (device: Device) => void;
}) {
  const { t } = useT();
  const { hasPermission } = usePermissions();
  const sendStaff = useSendStaffToDevice();
  const query = useDevice(registered.device.public_id);

  return (
    <QueryBoundary
      query={query}
      loading={<Skeleton className="h-24 w-full" />}
      isEmpty={() => false}
    >
      {(device) => {
        const canDiscover = device.receives_only !== true;
        const canSend =
          device.accepts_commands === true &&
          device.commands_enabled === true &&
          hasPermission("device.update");

        return (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              {canDiscover || canSend
                ? t("device_wizard.done_intro")
                : t("device_wizard.done_no_roster")}
            </p>
            <div className="flex flex-wrap gap-2">
              {canDiscover && (
                <Button type="button" onClick={() => onDiscover(device)}>
                  {t("devices_page.discover_users")}
                </Button>
              )}
              {canSend && (
                <Button
                  type="button"
                  variant="outline"
                  disabled={sendStaff.isPending}
                  onClick={() =>
                    sendStaff.mutate(device.public_id, {
                      onSuccess: (r) =>
                        toast.success(
                          t("device_commands.send_staff_queued", undefined, {
                            queued: r.queued,
                            skipped: r.skipped,
                          }),
                        ),
                      onError: (e) =>
                        toast.error(
                          apiErrorMessage(e, t("device_commands.failed")),
                        ),
                    })
                  }
                >
                  {t("device_commands.send_staff")}
                </Button>
              )}
            </div>
            <DialogFooter className="sm:justify-between">
              <Button variant="ghost" asChild>
                <Link href={`/devices/${device.public_id}`}>
                  {t("device_wizard.open_device")}
                </Link>
              </Button>
              <Button type="button" onClick={onClose}>
                {t("device_token.done")}
              </Button>
            </DialogFooter>
          </div>
        );
      }}
    </QueryBoundary>
  );
}
