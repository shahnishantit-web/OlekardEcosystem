import { NextRequest, NextResponse } from 'next/server';

const AUTH_ROUTES = /^\/(auth|login|api\/auth)/;

export async function middleware(request: NextRequest): Promise<NextResponse> {
  const token = request.cookies.get('admin_token')?.value;
  const isAuthRoute = AUTH_ROUTES.test(request.nextUrl.pathname);

  if (!token && !isAuthRoute) {
    const loginUrl = new URL('/login', request.url);
    loginUrl.searchParams.set('callbackUrl', request.nextUrl.pathname);
    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ['/((?!_next/static|_next/image|favicon.ico).*)'],
};
