import { FluxFiles } from './fluxfiles';

export { FluxFiles, FluxFilesCommand };
export { fileInfo, selectedFiles } from './convert';
export type { FileInfo } from './convert';
export type { FluxFilesConfig } from './config';
export default FluxFiles;

import { FluxFilesCommand } from './fluxfilescommand';

declare module '@ckeditor/ckeditor5-core' {
  interface PluginsMap {
    [FluxFiles.pluginName]: FluxFiles;
  }
}
