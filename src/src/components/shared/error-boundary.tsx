"use client";

import { Component, type ReactNode } from "react";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

/**
 * The default fallback, as a function component so it can call `useT()` —
 * the boundary itself is a class and cannot use hooks.
 */
function ErrorFallback({
  message,
  onReset,
}: {
  message: string | undefined;
  onReset: () => void;
}) {
  const { t } = useT();

  return (
    <div className="flex flex-col items-center justify-center py-16 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-full bg-destructive/10">
        <AlertTriangle className="h-6 w-6 text-destructive" />
      </div>
      <h3 className="mt-4 text-lg font-semibold text-foreground">
        {t("error.title", "Something went wrong")}
      </h3>
      <p className="mt-1 max-w-sm text-sm text-muted-foreground">
        {message ?? t("error.unexpected", "An unexpected error occurred")}
      </p>
      <Button variant="outline" className="mt-6" onClick={onReset}>
        <RefreshCw className="mr-2 h-4 w-4" />
        {t("common.try_again", "Try again")}
      </Button>
    </div>
  );
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  render() {
    if (this.state.hasError) {
      if (this.props.fallback) return this.props.fallback;

      return (
        <ErrorFallback
          message={this.state.error?.message}
          onReset={() => this.setState({ hasError: false, error: null })}
        />
      );
    }

    return this.props.children;
  }
}
