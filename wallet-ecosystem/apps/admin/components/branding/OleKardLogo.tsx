import Image from 'next/image';

export function OleKardLogo() {
  return (
    <div className="mb-10">
      <Image
        src="/olekard-logo.png"
        alt="OleKard Admin Platform"
        width={526}
        height={114}
        priority
        style={{ width: '240px', height: 'auto' }}
      />
    </div>
  );
}
