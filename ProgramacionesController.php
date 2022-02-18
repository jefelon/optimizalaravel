public function getProgramacionesDisponibles(ProgramacionesDisponiblesRequest $request){

       try{
           $user = auth()->user();

           if($user->type=="admin"){

               $programaciones = TransporteProgramacion::where('terminal_origen_id',$request->origen_id)
                   ->where('active',true)
                   ->where('terminal_destino_id', $request->destino_id);

           }else{
               $programaciones = TransporteProgramacion::where('terminal_origen_id',$request->origen_id)
               ->where('terminal_destino_id', $request->destino_id)
                ->where('active',true)

                ->WhereEqualsOrBiggerDate($request->fecha_salida);
               $date = Carbon::parse($request->fecha_salida);
               $today = Carbon::now();

                /* váliddo si es el mismo dia  */
                if($date->isSameDay($today)){
                    /* Si es el mismo traigo las programaciones que aun no hayan cumplido la hora */
                    $time = date('H:i:s',strtotime("-120 minutes")); //doy una hora para que aún esté disponible la programación
                    $programaciones->whereRaw("TIME_FORMAT(hora_salida,'%H:%i:%s') >= '{$time}'");
                }
           }




            $listProgramaciones = $programaciones->get();

            $viajes = collect([]);



            foreach($listProgramaciones as $programacion){

                $pasajes = collect([]);

                $programacionPadre = $programacion->programacion;

                $rutas = $programacionPadre->rutas()->get();

                $date = new Carbon(sprintf('%s %s', $request->fecha_salida,$programacion->hora_salida));

                $viaje = TransporteViajes::where('terminal_origen_id',$programacion->terminal_origen_id)
                ->where('terminal_destino_id',$programacion->terminal_destino_id)
                ->whereTime('hora_salida', $programacion->hora_salida)
                ->whereDate('fecha_salida', $request->fecha_salida )
                ->where('programacion_id',$programacionPadre->id)
                ->first();

                $viaje = !is_null($viaje) ? $viaje : TransporteViajes::create([
                    'terminal_origen_id' => $programacion->terminal_origen_id,
                    'hora_salida' => $programacion->hora_salida,
                    'fecha_salida' => $request->fecha_salida,
                    'vehiculo_id' => $programacion->vehiculo_id,
                    'terminal_destino_id' => $programacion->terminal_destino_id,
                    'programacion_id' => $programacionPadre->id
                ]);

                $viaje->update([
                    'vehiculo_id' => $programacion->vehiculo_id,
                ]);

                $viajes->push($viaje);

                $rutas->prepend($programacionPadre->origen);
                $rutas->push($programacionPadre->destino);

                // return response()->json($viaje->terminal_origen_id);
                $indexOrigen = $this->getPositionInRoute($viaje->origen,$rutas);
                $indexDestino = $this->getPositionInRoute($viaje->destino, $rutas);

                $mayores = $this->getRutasMayores($indexOrigen,$rutas)->pluck('id');
                $menores = $this->getRutasMenores($indexOrigen,$rutas)->pluck('id');

                $intermedios = $this->getRoutesBeetween($indexOrigen,$indexDestino,$rutas)->pluck('id');

                $listMenores = TransporteProgramacion::with('origen','destino')
                ->whereIn('terminal_origen_id',$menores)
                ->whereIn('terminal_destino_id',$mayores)
                ->where('programacion_id',$programacionPadre->id)
                ->get(); // obtengo todas las programaciones que vienen hacia mi


                $list = TransporteProgramacion::with('origen','destino')
                ->where('terminal_origen_id',$viaje->terminal_origen_id)
                ->where('programacion_id',$programacionPadre->id)
                ->get(); // obtengo todas las programaciones que vienen hacia mi


                $listIntermedios = TransporteProgramacion::with('origen','destino')
                ->whereIn('terminal_origen_id',$intermedios)
                ->where('programacion_id',$programacionPadre->id)
                ->get(); // obtengo los intemedios entre el punto de origen y destino si es que existen
                

                foreach($listMenores as $menor){
                    $timeClone = clone $date;

                    $tiempoEstimado = TransporteProgramacion::where('terminal_origen_id',$menor->terminal_origen_id)
                    ->where('terminal_destino_id',$viaje->terminal_origen_id)
                    ->where('programacion_id',$programacionPadre->id)
                    ->first();


                    $timeClone = $timeClone->subMinutes($tiempoEstimado->tiempo_estimado);

                    $travels = TransporteViajes::where('terminal_origen_id',$menor->terminal_origen_id)
                    ->where('terminal_destino_id',$menor->terminal_destino_id)
                    ->whereDate('fecha_salida',$timeClone->format('Y-m-d'))
                    ->whereTime('hora_salida' , $timeClone->format('H:i:s'))
                    ->where('programacion_id',$programacionPadre->id)
                    ->get();


                   $searchPasajes = TransportePasaje::with( 'origen', 'destino', 'pasajero','document:id,document_type_id')
                   ->whereIn('viaje_id',$travels->pluck('id'))
                   ->where('estado_asiento_id','!=',4) //diferente de cancelado
                   ->get();

                   $pasajes = [...$pasajes, ...$searchPasajes];

                }

                foreach($listIntermedios as $intermedio){
                    $timeClone = clone $date;

                    $tiempoEstimado = TransporteProgramacion::where('terminal_origen_id',$viaje->terminal_origen_id)
                    ->where('terminal_destino_id',$intermedio->terminal_origen_id)
                    ->where('programacion_id',$programacionPadre->id)
                    ->first();

                    if(is_null($tiempoEstimado)) continue;
                    

                    $timeClone = $timeClone->addMinutes($tiempoEstimado->tiempo_estimado);

                    $travels = TransporteViajes::where('terminal_origen_id',$intermedio->terminal_origen_id)
                    ->where('terminal_destino_id',$intermedio->terminal_destino_id)
                    ->whereDate('fecha_salida',$timeClone->format('Y-m-d'))
                    ->whereTime('hora_salida', $timeClone->format('H:i:s'))
                    ->where('programacion_id',$programacionPadre->id)
                    ->get();


                   $searchPasajes = TransportePasaje::with('origen', 'destino', 'pasajero','document:id,document_type_id')
                   ->whereIn('viaje_id',$travels->pluck('id'))
                   ->where('estado_asiento_id','!=',4) //diferente de cancelado
                   ->get();

                   $pasajes = [...$pasajes, ...$searchPasajes];



                }

                foreach($list as $item){
                    $travels = TransporteViajes::where('terminal_origen_id',$item->terminal_origen_id)
                    ->where('terminal_destino_id',$item->terminal_destino_id)
                    ->whereDate('fecha_salida', $request->fecha_salida)
                    ->whereTime('hora_salida', $programacion->hora_salida)
                    ->where('programacion_id',$programacionPadre->id)
                    ->get();


                   $searchPasajes = TransportePasaje::with('origen', 'destino', 'pasajero','document:id,document_type_id')
                   ->whereIn('viaje_id',$travels->pluck('id'))
                   ->where('estado_asiento_id','!=',4) //diferente de cancelado
                   ->get();

                   $pasajes = [...$pasajes, ...$searchPasajes];

                }


                $viaje->load('vehiculo.seats');
                $viaje->setAttribute('asientos_ocupados',$pasajes);
                

            }


            return response()->json( [
                'programaciones' => $viajes
            ]);

       }catch(Exception $e){

        return response()->json([
            'programaciones' => [],
            'error' => $e->getMessage() ,
            'line' => $e->getLine()
        ]);
       }

    }
