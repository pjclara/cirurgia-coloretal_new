import { ImgHTMLAttributes } from 'react';

export default function AppLogoIcon({ className, ...props }: ImgHTMLAttributes<HTMLImageElement>) {
    return <img src="/logo-cores.png" alt="Logo Cirurgia Colorectal" className={className} {...props} />;
}
