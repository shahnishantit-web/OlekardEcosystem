'use client';

// T091: Displays a secret reference key name with masked value — FR-014.
// Never renders raw credentials. Provides copy-key-name button only.
import { useState } from 'react';

interface SecretRef {
  key: string;
  masked: string;
}

interface Props {
  label: string;
  value: SecretRef | null | undefined;
  className?: string;
}

export function SecretReferenceField({ label, value, className = '' }: Props) {
  const [copied, setCopied] = useState(false);

  if (!value) {
    return (
      <div className={`space-y-1 ${className}`}>
        <p className="text-sm font-medium text-gray-700">{label}</p>
        <p className="text-sm text-gray-400 italic">Not configured</p>
      </div>
    );
  }

  const handleCopy = async () => {
    await navigator.clipboard.writeText(value.key);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className={`space-y-1 ${className}`}>
      <p className="text-sm font-medium text-gray-700">{label}</p>
      <div className="flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 px-3 py-2">
        <code className="flex-1 text-sm text-gray-800">{value.key}</code>
        <span className="font-mono text-sm text-gray-400">{value.masked}</span>
        <button
          type="button"
          onClick={handleCopy}
          className="rounded px-2 py-0.5 text-xs font-medium text-blue-600 hover:bg-blue-50"
          aria-label={`Copy key name for ${label}`}
        >
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
    </div>
  );
}
