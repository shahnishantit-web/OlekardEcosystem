interface PageContainerProps {
  children: React.ReactNode;
}

export function PageContainer({ children }: PageContainerProps) {
  return (
    <div className="px-8 lg:px-9 py-8 max-w-[1500px] mx-auto w-full">
      {children}
    </div>
  );
}
