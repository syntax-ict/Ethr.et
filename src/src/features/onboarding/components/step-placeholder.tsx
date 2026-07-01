import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";

interface StepPlaceholderProps {
  title: string;
  description: string;
  items: string[];
  onNext: (data: Record<string, unknown>) => void;
  onBack: () => void;
  isLast?: boolean;
}

export function StepPlaceholder({
  title,
  description,
  items,
  onNext,
  onBack,
  isLast,
}: StepPlaceholderProps) {
  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-semibold">{title}</h2>
        <p className="mt-1 text-sm text-muted-foreground">{description}</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Configuration</CardTitle>
          <CardDescription>
            Pre-populated from your selected template. Edit as needed.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <ul className="space-y-2">
            {items.map((item) => (
              <li key={item} className="flex items-center gap-2 text-sm">
                <div className="h-1.5 w-1.5 rounded-full bg-primary" />
                {item}
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      <div className="flex justify-between">
        <Button variant="outline" onClick={onBack}>
          Back
        </Button>
        <Button onClick={() => onNext({})}>
          {isLast ? "Launch Dashboard" : "Next"}
        </Button>
      </div>
    </div>
  );
}
