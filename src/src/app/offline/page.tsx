'use client';

import { WifiOff, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';

export default function OfflinePage() {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center p-6 text-center bg-background">
      <div className="flex h-20 w-20 items-center justify-center rounded-full bg-muted">
        <WifiOff className="h-10 w-10 text-muted-foreground" />
      </div>
      <h1 className="mt-6 text-2xl font-bold">You are offline</h1>
      <p className="mt-2 max-w-sm text-muted-foreground">
        No internet connection. Check your network and try again.
        Attendance you record offline will sync automatically when you reconnect.
      </p>
      <p className="mt-1 text-sm text-muted-foreground">
        ከኢንተርኔት ጋር ግንኙነት የለም። ኔትወርክዎን ያረጋግጡ።
      </p>
      <Button className="mt-6" onClick={() => window.location.reload()}>
        <RefreshCw className="mr-2 h-4 w-4" /> Try Again
      </Button>
    </div>
  );
}
