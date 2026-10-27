interface Props {
  amountMinor: number;
  currency: string;
}

export function CurrencyAmount({ amountMinor, currency }: Props) {
  const formatted = new Intl.NumberFormat(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amountMinor / 100);

  return (
    <span className="tabular-nums">
      {currency} {formatted}
    </span>
  );
}
